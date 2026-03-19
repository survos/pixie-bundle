<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Entity\StrTranslation;
use Survos\Lingua\Core\Identity\HashUtil;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('pixie:babel:ensure', 'Ensure Babel STR + STR_TR stub rows exist from Pixie Row.strCodes', aliases: ['pixie:ensure'])]
final class PixieBabelEnsureCommand
{
    private const string STUB_ENGINE = 'babel';

    public function __construct(
        private readonly PixieService $pixieService,
        private readonly ManagerRegistry $doctrine,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pixie code (e.g. larco, cleveland)')] string $pixieCode,
        #[Option('Doctrine entity manager name for the app DB (where Babel STR/STR_TR live).')] string $em = 'default',
        #[Option('Target locales (comma-separated). Defaults to config targetLocales or enabled_locales (minus source).')] ?string $targets = null,

        #[Option('Provider engine to pass to Lingua later (stored in meta only).')] ?string $provider = null,

        #[Option('Create missing STR_TR stub rows (default: yes).')] bool $ensureTr = true,
        #[Option('Max rows to scan (0 = all).')] int $limit = 0,
        #[Option('Flush batch size (rows).')] int $batch = 500,
        #[Option('Do not write anything.')] ?bool $dryRun = null,
        #[Option('Update existing STR rows (source/context/meta).')] ?bool $force = null,
        #[Option('Context prefix (default: pixie).')] string $contextPrefix = 'pixie',
    ): int {
        $dryRun = (bool) $dryRun;
        $force  = (bool) $force;
        $provider ??= 'libre';

        $ctx = $this->pixieService->getReference($pixieCode);

        /** @var EntityManagerInterface $appEm */
        $appEm = $this->doctrine->getManager($em);
        assert($appEm instanceof EntityManagerInterface);

        $sourceLocale = HashUtil::normalizeLocale((string) ($ctx->config->getSourceLocale() ?? 'en'));
        if ($sourceLocale === '') {
            $sourceLocale = 'en';
        }

        $configTargets = $ctx->config->babel?->targetLocales ?? null;
        $targetLocales = $this->resolveTargets($targets, $configTargets, $sourceLocale);

        if ($targetLocales === []) {
            $io->warning('No target locales resolved; will only ensure STR rows (no STR_TR stubs).');
            $ensureTr = false;
        }

        $io->title(sprintf('pixie:babel:ensure %s', $pixieCode));
        $io->definitionList(
            ['pixie db' => (string) ($ctx->config->pixieFilename ?? '(unknown)')],
            ['source locale' => $sourceLocale],
            ['targets' => $ensureTr ? implode(', ', $targetLocales) : '(none)'],
            ['stub engine' => self::STUB_ENGINE],
            ['provider' => $provider ?? '(none)'],
            ['app EM' => $em],
            ['dry-run' => $dryRun ? 'yes' : 'no'],
            ['force' => $force ? 'yes' : 'no'],
            ['limit' => $limit ?: '(all)'],
        );

        $rowRepo = $ctx->repo(Row::class);

        $qb = $rowRepo->createQueryBuilder('r')
            ->orderBy('r.id', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $iter = $qb->getQuery()->toIterable();

        $buf = [];
        $processed = 0;

        $totalCreatedStr = 0;
        $totalUpdatedStr = 0;
        $totalCreatedTr  = 0;
        $totalSeen       = 0;

        foreach ($iter as $row) {
            ++$processed;
            $buf[] = $row;

            if (\count($buf) >= $batch) {
                [$cStr, $uStr, $cTr, $seen] = $this->processBatch(
                    $appEm,
                    $pixieCode,
                    $buf,
                    $sourceLocale,
                    $targetLocales,
                    $provider,
                    $contextPrefix,
                    $dryRun,
                    $force,
                    $ensureTr
                );

                $totalCreatedStr += $cStr;
                $totalUpdatedStr += $uStr;
                $totalCreatedTr  += $cTr;
                $totalSeen       += $seen;
                $buf = [];
            }
        }

        if ($buf !== []) {
            [$cStr, $uStr, $cTr, $seen] = $this->processBatch(
                $appEm,
                $pixieCode,
                $buf,
                $sourceLocale,
                $targetLocales,
                $provider,
                $contextPrefix,
                $dryRun,
                $force,
                $ensureTr
            );

            $totalCreatedStr += $cStr;
            $totalUpdatedStr += $uStr;
            $totalCreatedTr  += $cTr;
            $totalSeen       += $seen;
        }

        $io->success(sprintf(
            'Done. Rows=%d SeenCodes=%d STR(created=%d,updated=%d) STR_TR(created=%d)%s',
            $processed,
            $totalSeen,
            $totalCreatedStr,
            $totalUpdatedStr,
            $totalCreatedTr,
            $dryRun ? ' (dry-run: no writes)' : ''
        ));

        $io->note('Next: run babel:push then babel:pull.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string>|null $configTargets
     * @return list<string>
     */
    private function resolveTargets(?string $targetsOpt, ?array $configTargets, string $sourceLocale): array
    {
        $targets = [];

        if ($targetsOpt !== null && trim($targetsOpt) !== '') {
            $targets = array_map('trim', explode(',', $targetsOpt));
        } elseif (is_array($configTargets) && $configTargets !== []) {
            $targets = array_map('trim', $configTargets);
        } else {
            $targets = array_map('trim', $this->enabledLocales);
        }

        $targets = array_values(array_filter($targets, fn($t) => $t !== '' && HashUtil::normalizeLocale($t) !== HashUtil::normalizeLocale($sourceLocale)));
        $targets = array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $targets)));

        return $targets;
    }

    /**
     * @param list<Row> $rows
     * @param list<string> $targetLocales
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function processBatch(
        EntityManagerInterface $appEm,
        string $pixieCode,
        array $rows,
        string $sourceLocale,
        array $targetLocales,
        ?string $provider,
        string $contextPrefix,
        bool $dryRun,
        bool $force,
        bool $ensureTr
    ): array {
        $want = [];
        $seen = 0;

        foreach ($rows as $row) {
            $map = is_array($row->strCodes ?? null) ? $row->strCodes : [];
            if ($map === []) {
                continue;
            }

            foreach ($map as $field => $code) {
                if (!is_string($field) || $field === '' || !is_string($code) || $code === '') {
                    continue;
                }

                $text = $this->extractSourceText($row, $field);
                if ($text === '') {
                    continue;
                }

                if (!isset($want[$code])) {
                    $want[$code] = [
                        'source' => $text,
                        'context' => sprintf('%s:%s:%s', $contextPrefix, $pixieCode, $field),
                        'meta' => ['pixie' => $pixieCode, 'field' => $field],
                    ];
                    $seen++;
                }
            }
        }

        if ($want === []) {
            return [0, 0, 0, 0];
        }

        $codes = array_keys($want);

        /** @var Str[] $existing */
        $existing = $appEm->getRepository(Str::class)->findBy(['code' => $codes]);

        $byCode = [];
        foreach ($existing as $str) {
            $byCode[$str->code] = $str;
        }

        $createdStr = 0;
        $updatedStr = 0;

        foreach ($want as $code => $payload) {
            $source  = (string) $payload['source'];
            $context = (string) $payload['context'];
            $meta    = (array) $payload['meta'];

            $str = $byCode[$code] ?? null;

            if (!$str) {
                $str = new Str();
                $str->code = $code;
                $str->sourceLocale = $sourceLocale;
                $str->source = $source;
                $str->context = $context;
                $str->meta = $meta;

                if (!$dryRun) {
                    $appEm->persist($str);
                }
                $createdStr++;
                $byCode[$code] = $str;
                continue;
            }

            if ($force) {
                $dirty = false;

                if (($str->source ?? '') === '') {
                    $str->source = $source;
                    $dirty = true;
                }
                if (($str->sourceLocale ?? '') === '') {
                    $str->sourceLocale = $sourceLocale;
                    $dirty = true;
                }
                if (($str->context ?? '') === null || $str->context === '') {
                    $str->context = $context;
                    $dirty = true;
                }

                $old = is_array($str->meta ?? null) ? $str->meta : [];
                $merged = $old + $meta;
                if ($merged !== $old) {
                    $str->meta = $merged;
                    $dirty = true;
                }

                if ($dirty) {
                    $updatedStr++;
                }
            }
        }

        $createdTr = 0;

        if ($ensureTr && $targetLocales !== []) {
            // IMPORTANT: existence is by (strCode, targetLocale) ONLY.
            /** @var StrTranslation[] $trs */
            $trs = $appEm->getRepository(StrTranslation::class)->findBy([
                'strCode' => $codes,
                'targetLocale' => $targetLocales,
            ]);

            $have = [];
            foreach ($trs as $tr) {
                $have[$tr->strCode.'|'.$tr->targetLocale] = true;
            }

            foreach ($codes as $code) {
                foreach ($targetLocales as $loc) {
                    $k = $code.'|'.$loc;
                    if (isset($have[$k])) {
                        continue;
                    }

                    $tr = new StrTranslation();
                    $tr->strCode = $code;
                    $tr->targetLocale = $loc;
                    $tr->engine = self::STUB_ENGINE; // canonical stub marker
                    $tr->text = null;
                    $tr->meta = array_filter([
                        'pixie' => $pixieCode,
                        'providerEngine' => $provider,
                    ]);

                    if (!$dryRun) {
                        $appEm->persist($tr);
                    }
                    $createdTr++;
                }
            }
        }

        if (!$dryRun) {
            $appEm->flush();
            $appEm->clear(Str::class);
            $appEm->clear(StrTranslation::class);
        }

        return [$createdStr, $updatedStr, $createdTr, $seen];
    }

    private function extractSourceText(Row $row, string $field): string
    {
        if ($field === 'label') {
            return trim((string) ($row->rawLabel ?? ''));
        }

        $data = $row->data ?? [];
        $val = $data[$field] ?? '';
        return is_string($val) ? trim($val) : '';
    }
}
