<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Entity\StatFacet;
use Survos\PixieBundle\Entity\StatProperty;
use Survos\PixieBundle\Service\PixieService;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:show', 'Show configuration, property usage, facet distributions, Meili settings, and translation-pointer coverage.')]
final class PixieStatsShowCommand extends Command
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly ?MeiliService $meili = null,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pixie code (e.g. larco, cleveland)')] string $pixieCode,
        #[Argument('Core/table code (e.g. obj, per). If omitted, show all cores.')] ?string $core = null,

        #[Option('Include a translation-pointer coverage section (t_codes).')] bool $pointers = true,
        #[Option('Max rows to scan for pointer coverage (0 = all).')] int $pointerLimit = 0,

        #[Option('Show Meilisearch settings and compare filterable attributes to expected candidates.')] bool $meili = true,
    ): int {
        $ctx = $this->pixie->getReference($pixieCode);
        $cfg = $ctx->config;

        // Registry-first names/locales. Adjust these accessors to match your PixieContext/Registry API.
        $baseIndexName = method_exists($cfg, 'getBaseName') ? (string) $cfg->getBaseName() : $pixieCode;

        // Prefer an explicit supported-locale list from registry/config.
        // Fallback order: cfg->getTargetLocales(), cfg->getLocales(), enabled_locales param, ['en'].
        $locales = [];
        if (method_exists($cfg, 'getTargetLocales') && is_array($cfg->getTargetLocales())) {
            $locales = $cfg->getTargetLocales();
        } elseif (method_exists($cfg, 'getLocales') && is_array($cfg->getLocales())) {
            $locales = $cfg->getLocales();
        }
        $locales = array_values(array_filter(array_map('strval', $locales)));
        if ($locales === []) {
            // last-resort fallback; keep this boring and explicit
            $locales = ['en'];
        }

        $io->title(sprintf('Pixie: %s%s', $pixieCode, $core ? " / $core" : ''));

        $io->section('Registry / identity');
        $io->definitionList(
            ['pixie code' => $pixieCode],
            ['base index name' => $baseIndexName],
            ['locales' => implode(', ', $locales)],
        );

        $io->section('Config');
        $io->definitionList(
            ['pixie db' => (string) ($cfg->pixieFilename)],
            ['source locale' => (string) ($cfg->babel->locale ?? '(none)')],
            ['data dir' => (string) ($cfg->dataDir ?? '(none)')],
            ['type' => $cfg->isSystem() ? 'system' : 'museum'],
            ['visibility' => (string) $cfg->visibility],
        );

        $tables = $cfg->tables;
        if ($core) {
            $tables = isset($tables[$core]) ? [$core => $tables[$core]] : [];
        }

        if ($tables === []) {
            $io->warning($core ? "Core '$core' not found in config." : 'No tables found in config.');
            return Command::SUCCESS;
        }

        $io->section('Translatable fields (from YAML config)');
        $rows = [];
        foreach ($tables as $tName => $t) {
            $trs = method_exists($t, 'getTranslatable') ? $t->getTranslatable() : [];
            $rows[] = [$tName, $trs ? implode(', ', $trs) : '(none)'];
        }
        $io->table(['core', 'translatable'], $rows);

        // Existing stats tables (if present)
        // Keep the criteria minimal; do not guess at renamed columns. If you changed owner_code -> base_name,
        // you should update the Stat* entities/repositories accordingly and then update this criteria in one place.
        $propCriteria = ['owner_code' => $pixieCode];
        $facetCriteria = ['owner_code' => $pixieCode];
        if ($core) {
            $propCriteria['core'] = $core;
            $facetCriteria['core'] = $core;
        }

        $props = $ctx->repo(StatProperty::class)->findBy($propCriteria, ['core' => 'ASC', 'property' => 'ASC']);
        if ($props) {
            $rows = [];
            foreach ($props as $p) {
                $rows[] = [$p->core, $p->property, $p->non_empty, $p->total];
            }
            $io->section('Property non-empty counts');
            $io->table(['core', 'property', 'non_empty', 'total'], $rows);
        }

        $facets = $ctx->repo(StatFacet::class)->findBy($facetCriteria, ['core' => 'ASC', 'property' => 'ASC', 'count' => 'DESC']);
        if ($facets) {
            $rows = [];
            foreach ($facets as $f) {
                $rows[] = [$f->core, $f->property, $f->value, $f->count];
            }
            $io->section('Facet distributions');
            $io->table(['core', 'property', 'value', 'count'], $rows);
        }

        if ($meili) {
            $this->renderMeiliSettingsAudit($io, $ctx, $baseIndexName, $locales, $tables, $facetCriteria);
        }

        if ($pointers) {
            $this->renderPointerCoverage($io, $ctx, $tables, $pointerLimit);
        }

        return Command::SUCCESS;
    }

    /**
     * Meilisearch settings: show current settings per locale-specific UID and compare filterables to expected candidates.
     */
    private function renderMeiliSettingsAudit(
        SymfonyStyle $io,
        mixed $ctx,
        string $baseIndexName,
        array $locales,
        array $tables,
        array $facetCriteria,
    ): void {
        $io->section('Meilisearch settings audit');

        if (!$this->meili) {
            $io->warning('MeiliService not available; cannot inspect index settings.');
            return;
        }

        // Build “expected” filterable candidates:
        // 1) configured facets (if your table config exposes them), and
        // 2) observed facet properties from StatFacet rows.
        $expected = [];

        // (1) configured facets in YAML (if available)
        foreach ($tables as $core => $t) {
            foreach (['getFacetable', 'getFacets', 'getFacetFields'] as $method) {
                if (method_exists($t, $method)) {
                    $cfgFacets = $t->{$method}();
                    if (is_array($cfgFacets)) {
                        foreach ($cfgFacets as $f) {
                            if (is_string($f) && $f !== '') {
                                $expected[$f] = true;
                            }
                        }
                    }
                    break;
                }
            }
        }

        // (2) observed facet properties from StatFacet
        $facetRepo = $ctx->repo(StatFacet::class);
        $observedFacets = $facetRepo->findBy($facetCriteria, ['property' => 'ASC']);
        foreach ($observedFacets as $f) {
            if (isset($f->property) && is_string($f->property) && $f->property !== '') {
                $expected[$f->property] = true;
            }
        }

        $expectedList = array_keys($expected);
        sort($expectedList);

        $io->writeln(sprintf('Expected filterable candidates (from config + StatFacet): %d', count($expectedList)));
        if ($expectedList) {
            $io->writeln(implode(', ', $expectedList));
        } else {
            $io->note('No facet candidates found in config or StatFacet. This may be valid for purely keyword indexes.');
        }

        foreach ($locales as $locale) {
            $uid = sprintf('%s_%s', $baseIndexName, $locale);

            try {
                $index = $this->meili->getClient()->index($uid);
                $settings = $index->getSettings();
            } catch (\Throwable $e) {
                $io->warning(sprintf('Unable to fetch settings for %s: %s', $uid, $e->getMessage()));
                continue;
            }

            $filterable = $settings['filterableAttributes'] ?? [];
            $sortable = $settings['sortableAttributes'] ?? [];
            $searchable = $settings['searchableAttributes'] ?? [];
            $displayed = $settings['displayedAttributes'] ?? [];

            $filterable = is_array($filterable) ? $filterable : [];
            $sortable = is_array($sortable) ? $sortable : [];
            $searchable = is_array($searchable) ? $searchable : [];
            $displayed = is_array($displayed) ? $displayed : [];

            sort($filterable);
            sort($sortable);
            sort($searchable);
            sort($displayed);

            $io->subsection(sprintf('Index: %s', $uid));
            $io->definitionList(
                ['filterableAttributes' => $filterable ? implode(', ', $filterable) : '(none)'],
                ['sortableAttributes' => $sortable ? implode(', ', $sortable) : '(none)'],
                ['searchableAttributes' => $searchable ? implode(', ', $searchable) : '(default / none)'],
                ['displayedAttributes' => $displayed ? implode(', ', $displayed) : '(default / none)'],
            );

            if ($expectedList) {
                $missing = array_values(array_diff($expectedList, $filterable));
                $extra = array_values(array_diff($filterable, $expectedList));

                if ($missing) {
                    $io->warning('Missing from filterableAttributes: ' . implode(', ', $missing));
                }
                if ($extra) {
                    $io->note('Extra in filterableAttributes (not in expected candidates): ' . implode(', ', $extra));
                }
                if (!$missing && !$extra) {
                    $io->success('filterableAttributes matches expected candidates.');
                }
            }
        }
    }

    /**
     * Scan Row entities and report t_codes coverage for configured translatable fields.
     *
     * Intentionally avoids SQLite JSON SQL so it remains portable.
     */
    private function renderPointerCoverage(
        SymfonyStyle $io,
        mixed $ctx,
        array $tables,
        int $limit,
    ): void {
        $io->section('Translation-pointer coverage (Row.t_codes)');

        $rowRepo = $ctx->repo(Row::class);

        $summary = [];
        foreach ($tables as $core => $t) {
            $translatable = method_exists($t, 'getTranslatable') ? $t->getTranslatable() : [];
            if ($translatable === []) {
                continue;
            }

            $qb = $rowRepo->createQueryBuilder('r')
                ->join('r.core', 'c')
                ->andWhere('c.code = :core')
                ->setParameter('core', $core)
                ->orderBy('r.id', 'ASC');

            if ($limit > 0) {
                $qb->setMaxResults($limit);
            }

            $rows = $qb->getQuery()->toIterable();

            $total = 0;
            $withAny = 0;
            $fieldHits = array_fill_keys($translatable, 0);

            foreach ($rows as $row) {
                ++$total;

                $map = method_exists($row, 'getStrCodeMap') ? $row->getStrCodeMap() : [];
                if ($map !== []) {
                    ++$withAny;
                }

                foreach ($translatable as $field) {
                    $code = $map[$field] ?? null;
                    if (is_string($code) && $code !== '') {
                        $fieldHits[$field] = ($fieldHits[$field] ?? 0) + 1;
                    }
                }
            }

            $summary[] = [
                $core,
                $total,
                $withAny,
                implode(', ', array_map(
                    fn(string $f) => sprintf('%s:%d', $f, (int) ($fieldHits[$f] ?? 0)),
                    $translatable
                )),
            ];
        }

        if ($summary === []) {
            $io->writeln('No translatable fields configured, or no rows found.');
            return;
        }

        $io->table(['core', 'rows scanned', 'rows with any t_codes', 'field coverage'], $summary);

        if ($limit > 0) {
            $io->note("Pointer coverage was computed from the first $limit rows per core.");
        }
    }
}
