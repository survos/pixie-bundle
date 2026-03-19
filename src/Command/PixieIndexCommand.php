<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\MeiliBundle\Service\IndexNameResolver as MeiliIndexNameResolver;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Entity\Term;
use Survos\PixieBundle\Entity\TermSet;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\MeiliIndexer;
use Survos\PixieBundle\Service\PixieDocumentProjector;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('pixie:index', 'Project Rows and index to Meili (preflights settings first)')]
final class PixieIndexCommand extends Command
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly PixieDocumentProjector $projector,
        private readonly MeiliIndexer $meiliIndexer,
        private readonly MeiliService $meili,
        private readonly LocaleContext $locale,
        private readonly MeiliIndexNameResolver $indexNames,
        #[Autowire('%kernel.environment%')] private readonly string $env,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixieCode')] ?string $pixieCode = null,

        #[Option('Locale to index (if omitted, uses pixie config: sourceLocale + targetLocales)')]
        ?string $locale = null,

        #[Option('Index all locales from pixie config (sourceLocale + targetLocales)')]
        bool $allLocales = false,

        #[Option('batch')]
        int $batch = 500,

        #[Option('limit')]
        ?int $limit = null,

        #[Option('offset')]
        int $offset = 0,

        #[Option('purge the meili index first')]
        ?bool $reset = null,

        #[Option('Only enqueue create+settings tasks; do not index documents')]
        bool $preflightOnly = false,

        #[Option('Skip preflight step (not recommended)')]
        bool $noPreflight = false,

        #[Option('Preview N projected docs per locale and DO NOT dispatch to Meili (0=off)')]
        int $preview = 0,

        #[Option('Show registry settings (schema/persisted/facets) for the base index')]
        bool $debugRegistry = false,

        #[Option('Show missing-field diagnostics against registry filterables/persisted')]
        bool $debugMissing = true,

        // NEW raw debugging switches
        #[Option('Dump basic Row info for previewed rows')]
        bool $dumpRow = false,

        #[Option('Dump Row->data (normalized payload) for previewed rows')]
        bool $dumpData = false,

        #[Option('Dump Row strCode map for previewed rows')]
        bool $dumpStr = false,

        #[Option('Dump TermSets + a few top terms (sanity check term ingestion)')]
        bool $dumpTerms = false,
    ): int {
        $pixieCode ??= getenv('PIXIE_CODE');
        if (!$pixieCode) {
            $io->error('Pass in pixieCode or set PIXIE_CODE env var');
            return Command::FAILURE;
        }

        $ctx = $this->pixie->getReference($pixieCode);
        $config = $ctx->config;

        $sourceLocale = $config->getSourceLocale($this->locale->getDefault());
        $targets = $config->getTargetLocales($this->locale->getEnabled(), $sourceLocale);

        $localesToIndex = [];
        if ($locale) {
            $localesToIndex = [$locale];
        } elseif ($allLocales || true) {
            $localesToIndex = array_values(array_unique(array_merge([$sourceLocale], $targets)));
        }
        if ($localesToIndex === []) {
            $localesToIndex = [$sourceLocale];
        }

        $limit ??= ($this->env === 'dev') ? 10 : 0;

        $io->title(sprintf('Pixie index: %s', $pixieCode));
        $io->writeln(sprintf('Locales: <info>%s</info>', implode(', ', $localesToIndex)));
        $io->writeln(sprintf('Preflight: <info>%s</info>', $noPreflight ? 'no' : 'yes'));

        if ($preview > 0) {
            $io->warning(sprintf('PREVIEW MODE: will project %d docs/locale and NOT dispatch to Meili.', $preview));
        }

        // -----------------------------------------------------------------
        // Optional: TermSets sanity check
        // -----------------------------------------------------------------
        if ($dumpTerms || $io->isVeryVerbose()) {
            $io->section('TermSets sanity check');
            /** @var list<TermSet> $sets */
            $sets = $ctx->repo(TermSet::class)->findBy([], ['id' => 'ASC']);
            if ($sets === []) {
                $io->warning('No TermSets found in pixie DB.');
            } else {
                $rows = [];
                foreach ($sets as $set) {
                    $count = (int)$ctx->repo(Term::class)->count(['termSet' => $set->id]);
                    $rows[] = [$set->id, $set->sourceLocale ?? '', $count];
                }
                $io->table(['setId', 'sourceLocale', 'terms'], $rows);

                if ($dumpTerms || $io->isDebug()) {
                    foreach (array_slice($sets, 0, 5) as $set) {
                        /** @var list<Term> $top */
                        $top = $ctx->repo(Term::class)->findBy(['termSet' => $set->id], ['count' => 'DESC'], 5);
                        $io->writeln(sprintf(
                            '<info>%s</info> top: %s',
                            $set->id,
                            implode(', ', array_map(
                                fn(Term $t) => sprintf('%s(%d)', $t->rawLabel ?? $t->label ?? $t->code, (int)($t->count ?? 0)),
                                $top
                            ))
                        ));
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // Registry diagnostics
        // -----------------------------------------------------------------
        $rawSettings = $this->meili->getRawIndexSettings();
        $baseSetting = $rawSettings[$pixieCode] ?? null;

        if (($debugRegistry || $io->isVerbose()) && $baseSetting) {
            $io->section(sprintf('Registry settings for base "%s"', $pixieCode));

            $schema = $baseSetting['schema'] ?? [];
            $filterable = $schema['filterableAttributes'] ?? [];
            $persisted = $baseSetting['persisted']['fields'] ?? ($baseSetting['persisted'] ?? []);
            $persisted = is_array($persisted) ? $persisted : [];

            $io->definitionList(
                ['class' => (string)($baseSetting['class'] ?? '')],
                ['primaryKey' => (string)($baseSetting['primaryKey'] ?? '')],
                ['persisted(count)' => (string)count($persisted)],
                ['filterable(count)' => (string)count((array)$filterable)],
            );

            if ($io->isVeryVerbose()) {
                $io->writeln('Schema (updateSettings payload):');
                $io->writeln(json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $io->writeln("\nPersisted fields:");
                $io->writeln(implode(', ', array_map('strval', $persisted)));
            }

            if ($io->isDebug()) {
                $io->writeln("\nFacet UI metadata:");
                $io->writeln(json_encode($baseSetting['facets'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        // -----------------------------------------------------------------
        // Step 1: Preflight
        // -----------------------------------------------------------------
        if (!$noPreflight) {
            $io->section('Preflight: enqueue updateSettings (no waits)');

            foreach ($localesToIndex as $loc) {
                $this->locale->run($loc, function () use ($io, $pixieCode, $sourceLocale, $loc, $reset): void {
                    $isMlFor = $this->indexNames->isMultiLingualFor($pixieCode, $sourceLocale);
                    $uid = $this->indexNames->uidFor($pixieCode, $loc, $isMlFor);

                    if ($reset) {
                        $this->meili->reset($uid);
                    }

                    if ($io->isVerbose()) {
                        $io->writeln(sprintf('Preflight %s', $uid));
                    }

                    $this->meiliIndexer->ensureIndexReady(
                        baseName: $pixieCode,
                        locale: $loc,
                        core: null,
                        primaryKey: 'id',
                        fallbackSourceLocale: $sourceLocale
                    );
                });
            }
        }

        if ($preflightOnly) {
            $io->success('Preflight complete (settings enqueued).');
            return Command::SUCCESS;
        }

        // -----------------------------------------------------------------
        // Step 2: Project + dispatch docs
        // -----------------------------------------------------------------
        $io->section('Indexing: project docs' . ($preview > 0 ? ' (preview only)' : ' and enqueue addDocuments'));

        foreach ($localesToIndex as $loc) {
            $this->locale->run($loc, function () use (
                $io, $pixieCode, $ctx, $sourceLocale, $loc,
                $batch, $limit, $offset, $preview, $debugMissing, $baseSetting,
                $dumpRow, $dumpData, $dumpStr
            ): void {
                $isMlFor = $this->indexNames->isMultiLingualFor($pixieCode, $sourceLocale);
                $uid = $this->indexNames->uidFor($pixieCode, $loc, $isMlFor);

                $io->section(sprintf('Meili index UID: %s (locale=%s)', $uid, $loc));

                $schema = (array)($baseSetting['schema'] ?? []);
                $expectedFilterable = array_values(array_unique(array_map('strval', (array)($schema['filterableAttributes'] ?? []))));
                $expectedPersisted  = $baseSetting['persisted']['fields'] ?? ($baseSetting['persisted'] ?? []);
                $expectedPersisted  = is_array($expectedPersisted) ? array_values(array_unique(array_map('strval', $expectedPersisted))) : [];

                if ($io->isVeryVerbose()) {
                    $io->writeln(sprintf(
                        'Expected filterables: %d | persisted: %d',
                        count($expectedFilterable),
                        count($expectedPersisted)
                    ));
                }

                $rows = $ctx->repo(Row::class)->findBy([], ['id' => 'ASC'], $limit ?: null, $offset);

                $docs = [];
                $i = 0;
                $previewLeft = $preview;

                foreach ($rows as $row) {
                    $projected = $this->projector->project($ctx, $row, $loc);

                    if ($debugMissing && ($io->isVerbose() || $preview > 0) && $baseSetting) {
                        $missingFilterable = $this->missingFields($expectedFilterable, $projected);
                        $missingPersisted  = $expectedPersisted ? $this->missingFields($expectedPersisted, $projected) : [];

                        if ($missingFilterable !== []) {
                            $io->writeln(sprintf(
                                '<comment>Row %s missing filterables:</comment> %s',
                                (string)$row->id,
                                implode(', ', $missingFilterable)
                            ));
                        }

                        if ($io->isVeryVerbose() && $missingPersisted !== []) {
                            $io->writeln(sprintf(
                                '<comment>Row %s missing persisted:</comment> %s',
                                (string)$row->id,
                                implode(', ', $missingPersisted)
                            ));
                        }
                    }

                    if ($previewLeft > 0) {
                        if ($dumpRow || $io->isVeryVerbose()) {
                            $io->writeln("\n--- raw row ---");
                            $io->writeln(json_encode([
                                'id' => (string)$row->id,
                                'core' => $row->core->code ?? (string)$row->core,
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        }
                        if ($dumpStr || $io->isVeryVerbose()) {
                            $io->writeln("\n--- row strCodes ---");
                            $io->writeln(json_encode($row->getStrCodeMap(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        }
                        if ($dumpData || $io->isVeryVerbose()) {
                            $io->writeln("\n--- row data ---");
                            $io->writeln(json_encode($row->data ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        }

                        $io->writeln("\n--- payload preview ---");
                        $io->writeln(json_encode($projected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                        $previewLeft--;
                        $i++;
                        if ($previewLeft === 0) {
                            break;
                        }
                        continue;
                    }

                    $docs[] = $projected;
                    $i++;

                    if (($i % $batch) === 0) {
                        $this->meiliIndexer->indexDocs($uid, $docs);
                        $docs = [];
                    }
                }

                if ($preview > 0) {
                    $io->success(sprintf('Previewed %d rows for %s (no dispatch).', $i, $uid));
                    return;
                }

                if ($docs) {
                    $this->meiliIndexer->indexDocs($uid, $docs);
                }

                $io->success(sprintf('Enqueued %d rows into %s.', $i, $uid));
            });
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $expected
     * @param array<string,mixed> $doc
     * @return list<string>
     */
    private function missingFields(array $expected, array $doc): array
    {
        $missing = [];
        foreach ($expected as $f) {
            if ($f === '' || $f === '*' || $f === '_meta') {
                continue;
            }
            if (!array_key_exists($f, $doc)) {
                $missing[] = $f;
                continue;
            }
            $v = $doc[$f];
            if (is_array($v) && $v === []) {
                $missing[] = $f . '([])';
            }
        }
        return $missing;
    }
}
