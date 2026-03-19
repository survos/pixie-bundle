<?php

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\TranslationBatcher;
use Doctrine\ORM\EntityManagerInterface;
use Survos\PixieBundle\Entity\Str;
use Survos\PixieBundle\Entity\StrTranslation;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('pixie:translate',
    'Create missing StrTranslation rows and optionally dispatch them',
help:<<<HELP
Prepare only (create missing StrTranslation rows):

    bin/console pixie:translate WAM --target=es|fr --batch=500

Prepare + dispatch (apply already-available translations; queue the rest):

    bin/console pixie:translate WAM --target=es|fr --dispatch --transport=async

Only check current status:

    bin/console pixie:translate WAM --status
HELP

)]
final class PixieTranslateCommand extends Command
{
    public function __construct(
        private PixieService $pixieService,
        #[Autowire('%kernel.enabled_locales%')] private array $supportedLocales,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('config code')] ?string $configCode,
        #[Option('target locales like es|fr|pt-BR')] ?string $target = null,
        #[Option('max Str rows to process')] int $limit = 0,
        #[Option('Str rows per batch', 'batch')] int $batchSize = 500,
        #[Option('just show counts, no changes', 'status')] ?bool $statusOnly = null,
        #[Option('no writes', 'dry')] ?bool $dryRun = null,

// Dispatch options
        #[Option('send pending rows now')] ?bool $dispatch = null,
        #[Option('message bus transport name')] string $transport = 'async',
        #[Option('force dispatch even if exists', 'force')] ?bool $forceDispatch = null,
        #[Option('only fetch existing, don’t queue new', 'fetch-only')] ?bool $fetchOnly = null,
    ): int {
        $configCode ??= getenv('PIXIE_CODE');
        if (!$configCode) {
            $io->error('export PIXIE_CODE or pass one in');
            return Command::FAILURE;
        }

        $ctx    = $this->pixieService->getReference($configCode);
        $em     = $ctx->em;       /** @var EntityManagerInterface $em */
        $config = $ctx->config;

        $from = $config->getSource()->locale;
        $to   = $target ? explode('|', $target) : $this->supportedLocales;

        $strRepo = $ctx->repo(Str::class);
        $total   = method_exists($strRepo, 'totalCount')
            ? $strRepo->totalCount()
            : (int)$strRepo->createQueryBuilder('s')->select('COUNT(s)')->getQuery()->getSingleScalarResult();

        $this->renderHeader($io, $configCode, $total, $from, $to);

        if ($statusOnly) {
            $io->writeln('<comment>status only</comment>');
            return Command::SUCCESS;
        }

        $batchSize = max(1, $batchSize);
        $limit     = max(0, $limit);

        $progress = new ProgressBar($io, $limit ?: $total);
        $progress->setFormat("<fg=white;bg=cyan> %status:-45s%</>\n%current%/%max% [%bar%] %percent:3s%%\n🏁  %estimated:-21s% %memory:21s%");
        $progress->start();

        $createdTotal          = 0;
        $createdByLocaleTotals = array_fill_keys($to, 0);
        $pendingByLocaleTotals = array_fill_keys($to, 0);
        $translatedTotals      = array_fill_keys($to, 0);
        $queuedTotals          = array_fill_keys($to, 0);

        $processed = 0;
        $batch = [];

        foreach ($this->iterateAllStr($strRepo, $batchSize) as $str) {
            if ($limit && $processed >= $limit) break;

            $batch[] = $str;

            $needFlush = count($batch) >= $batchSize
                || ($limit && $processed + count($batch) >= $limit);

            if ($needFlush) {
                if ($limit && $processed + count($batch) > $limit) {
                    $batch = array_slice($batch, 0, $limit - $processed);
                }

                [$created, $createdByLocale, $pendingByLocale, $translatedByLocale, $queuedByLocale] =
                    $this->processBatch(
                        $io, $em, $batch, $from, $to,
                        $dryRun ?? false,
                        $dispatch ?? false,
                        $transport ?? 'async',
                        $forceDispatch,
                        $fetchOnly
                    );

                $createdTotal += $created;
                foreach ($to as $loc) {
                    $createdByLocaleTotals[$loc] += $createdByLocale[$loc] ?? 0;
                    $pendingByLocaleTotals[$loc] += $pendingByLocale[$loc] ?? 0;
                    $translatedTotals[$loc]      += $translatedByLocale[$loc] ?? 0;
                    $queuedTotals[$loc]          += $queuedByLocale[$loc] ?? 0;
                }

                $processed += count($batch);
                $progress->advance(count($batch));
                $batch = [];
            }
        }

        // leftovers
        if (!empty($batch) && (!$limit || $processed < $limit)) {
            if ($limit && $processed + count($batch) > $limit) {
                $batch = array_slice($batch, 0, $limit - $processed);
            }
            [$created, $createdByLocale, $pendingByLocale, $translatedByLocale, $queuedByLocale] =
                $this->processBatch(
                    $io, $em, $batch, $from, $to,
                    $dryRun ?? false,
                    $dispatch ?? false,
                    $transport ?? 'async',
                    $forceDispatch,
                    $fetchOnly
                );

            $createdTotal += $created;
            foreach ($to as $loc) {
                $createdByLocaleTotals[$loc] += $createdByLocale[$loc] ?? 0;
                $pendingByLocaleTotals[$loc] += $pendingByLocale[$loc] ?? 0;
                $translatedTotals[$loc]      += $translatedByLocale[$loc] ?? 0;
                $queuedTotals[$loc]          += $queuedByLocale[$loc] ?? 0;
            }

            $processed += count($batch);
            $progress->advance(count($batch));
        }

        $progress->finish();
        $io->newLine(2);

        // Summary table
        $table = new Table($io);
        $table->setHeaderTitle("$configCode / $total strings");
        $table->setHeaders(['locale', 'created', 'pending', 'translated(now)', 'queued']);
        foreach ($to as $locale) {
            $table->addRow([
                $locale,
                $createdByLocaleTotals[$locale] ?? 0,
                $pendingByLocaleTotals[$locale] ?? 0,
                $translatedTotals[$locale] ?? 0,
                $queuedTotals[$locale] ?? 0,
            ]);
        }
        $table->addRow(new TableSeparator());
        $table->addRow([
            'TOTAL',
            $createdTotal,
            array_sum($pendingByLocaleTotals),
            array_sum($translatedTotals),
            array_sum($queuedTotals),
        ]);
        $table->render();

        if ($dryRun) $io->warning('dry-run: no DB changes were committed.');
        if ($dispatch) $io->note('Dispatched pending rows (existing translations applied, others queued).');

        return Command::SUCCESS;
    }

    /** @return \Generator<Str> */
    private function iterateAllStr($strRepo, int $batchSize): \Generator
    {
        if (method_exists($strRepo, 'iterateAll')) {
            yield from $strRepo->iterateAll($batchSize);
            return;
        }
        $q = $strRepo->createQueryBuilder('s')->orderBy('s.code', 'ASC')->getQuery();
        foreach ($q->toIterable([], \Doctrine\ORM\AbstractQuery::HYDRATE_OBJECT) as $row) {
            yield $row;
        }
    }

    /**
     * @return array{0:int,1:array<string,int>,2:array<string,int>,3:array<string,int>,4:array<string,int>}
     */
    private function processBatch(
        SymfonyStyle $io,
        EntityManagerInterface $em,
        array $batch,
        string $from,
        array $toLocales,
        bool $dryRun,
        bool $doDispatch,
        string $transport,
        ?bool $forceDispatch,
        ?bool $fetchOnly
    ): array {
        $createdByLocale = array_fill_keys($toLocales, 0);
        $pendingByLocale = array_fill_keys($toLocales, 0);
        $translatedByLoc = array_fill_keys($toLocales, 0);
        $queuedByLoc     = array_fill_keys($toLocales, 0);
        $created = 0;

        // Step 1: create missing StrTranslation (marking=new)
        $em->beginTransaction();
        try {
            $r = $this->batcher->ensureMissingForBatch($em, $batch, $toLocales);
            $created = $r['created'] ?? 0;
            foreach (($r['createdByLocale'] ?? []) as $loc => $n) {
                $createdByLocale[$loc] += $n;
            }

            if ($dryRun) {
                $em->rollback();
            } else {
                $em->flush();
                $em->commit();
            }
        } catch (\Throwable $e) {
            $em->rollback();
            throw $e;
        } finally {
            $em->clear(StrTranslation::class);
            $em->clear(Str::class);
        }

        // Step 2: gather pending (marking='new')
        $pending = $this->batcher->findPendingForBatch($em, $batch, $toLocales);
        foreach ($pending as $tr) {
            $pendingByLocale[$tr->locale] = ($pendingByLocale[$tr->locale] ?? 0) + 1;
        }

        // Step 3: optionally dispatch now and apply the ones that already exist
        if ($doDispatch && !$dryRun && $pending) {
            $em->beginTransaction();
            try {
                $res = $this->batcher->dispatchNow($em, $pending, $from, $transport, $forceDispatch, $fetchOnly);
                foreach (($res['translatedByLocale'] ?? []) as $loc => $n) {
                    $translatedByLoc[$loc] += $n;
                    // translated ones no longer pending
                    $pendingByLocale[$loc] = max(0, ($pendingByLocale[$loc] ?? 0) - $n);
                }
                foreach (($res['queuedByLocale'] ?? []) as $loc => $n) {
                    $queuedByLoc[$loc] += $n;
                }

                $em->flush();
                $em->commit();
            } catch (\Throwable $e) {
                $em->rollback();
                throw $e;
            } finally {
                $em->clear(StrTranslation::class);
                $em->clear(Str::class);
            }
        }

        // Per-batch line
        $fmt = fn(array $m) => implode(', ', array_map(fn($loc) => "$loc:".($m[$loc] ?? 0), $toLocales));
        $io->writeln(sprintf(
            'created [%s]  pending [%s]%s',
            $fmt($createdByLocale),
            $fmt($pendingByLocale),
            $doDispatch ? sprintf('  translated(now) [%s]  queued [%s]', $fmt($translatedByLoc), $fmt($queuedByLoc)) : ''
        ));

        return [$created, $createdByLocale, $pendingByLocale, $translatedByLoc, $queuedByLoc];
    }

    private function renderHeader(SymfonyStyle $io, string $configCode, int $total, string $from, array $to): void
    {
        $io->title("pixie:translate — $configCode");
        $io->writeln("<info>source:</info> $from");
        $io->writeln('<info>targets:</info> '.implode(', ', $to));
        $io->writeln("<info>total source strings:</info> $total");
        $io->newLine();
    }
}
