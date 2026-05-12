<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\MeiliBundle\Service\MeiliService;
use Survos\PixieBundle\Event\IndexDiscoveredEvent;
use Survos\PixieBundle\Event\IndexSyncCompletedEvent;
use Survos\PixieBundle\Service\IndexNamingService;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AsCommand('pixie:sync-indexes', 'Sync Meilisearch indexes with OwnerIndex records (via dispatch)')]
final class PixieSyncIndexesCommand
{
    public function __construct(
        private readonly PixieService $pixieService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?MeiliService $meilisearchClient, // Inject your Meilisearch client
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Specific pixie code to sync')] ?string $pixieCode = null,
        #[Option('Remove indexes not found in Meilisearch')] bool $prune = false,
        #[Option('Create missing Owner records for discovered indexes')] bool $createMissing = false,
        #[Option('Dry run - show what would be done without making changes')] bool $dryRun = false,
    ): int {
        $io->title('Syncing Meilisearch indexes with OwnerIndex records');

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be made');
        }

        // Get all indexes from Meilisearch
        $meiliIndexes = $this->getMeilisearchIndexes();
        $io->writeln("Found " . count($meiliIndexes) . " indexes in Meilisearch");

        $pixieIndexes = [];
        $unknownIndexes = [];
        $stats = [
            'discovered' => 0,
            'updated' => 0,
            'created' => 0,
            'pruned' => 0,
            'skipped' => 0,
        ];

        // Parse index names and categorize
        foreach ($meiliIndexes as $indexInfo) {
            dd($indexInfo);
            $indexName = $indexInfo['uid'];
            $parsed = IndexNamingService::parseIndexName($indexName);

            if ($parsed) {
                // Skip if we're filtering by pixie code
                if ($pixieCode && $parsed['pixieCode'] !== $pixieCode) {
                    $stats['skipped']++;
                    continue;
                }

                $pixieIndexes[] = [
                    'indexName' => $indexName,
                    'pixieCode' => $parsed['pixieCode'],
                    'locale' => $parsed['locale'],
                    'stats' => $indexInfo,
                ];
            } else {
                $unknownIndexes[] = $indexName;
            }
        }

        if ($unknownIndexes) {
            $io->section('Unknown Indexes (not pixie format)');
            foreach ($unknownIndexes as $indexName) {
                $io->writeln("  - $indexName");
            }
        }

        $io->section('Processing Pixie Indexes');
        $io->progressStart(count($pixieIndexes));

        foreach ($pixieIndexes as $indexData) {
            $indexName = $indexData['indexName'];
            $pixieCodeFound = $indexData['pixieCode'];
            $locale = $indexData['locale'];
            $indexStats = $indexData['stats'];

            try {
                // Check if Owner exists
                $owner = null;
                try {
                    $ctx = $this->pixieService->getReference($pixieCodeFound);
                    $owner = $ctx->owner;
                } catch (\Exception $e) {
                    if ($createMissing && !$dryRun) {
                        $io->writeln("\n  Creating missing owner for: $pixieCodeFound");
                        // Here you'd create the Owner record
                        // This depends on your Owner entity structure
                        $stats['created']++;
                    } else {
                        $io->writeln("\n  No owner found for pixie code: $pixieCodeFound (index: $indexName)");
                        $stats['skipped']++;
                        $io->progressAdvance();
                        continue;
                    }
                }

                // Dispatch discovery event with index information
                if (!$dryRun) {
                    $this->eventDispatcher->dispatch(
                        new IndexDiscoveredEvent(
                            indexName: $indexName,
                            pixieCode: $pixieCodeFound,
                            locale: $locale,
                            documentCount: $indexStats['numberOfDocuments'] ?? 0,
                            lastUpdate: $this->parseIndexDate($indexStats['updatedAt'] ?? null),
                            indexStats: $indexStats,
                            owner: $owner
                        )
                    );
                }

                $stats['discovered']++;

            } catch (\Exception $e) {
                $io->writeln("\n  Error processing $indexName: " . $e->getMessage());
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Handle pruning if requested
        if ($prune) {
            $stats['pruned'] = $this->pruneOrphanedRecords($io, $pixieIndexes, $dryRun, $pixieCode);
        }

        // Dispatch completion event
        if (!$dryRun) {
            $this->eventDispatcher->dispatch(
                new IndexSyncCompletedEvent(
                    totalIndexes: count($meiliIndexes),
                    pixieIndexes: count($pixieIndexes),
                    stats: $stats,
                    pixieCode: $pixieCode
                )
            );
        }

        // Summary
        $io->section('Sync Summary');
        $io->definitionList(
            ['Total Meilisearch indexes' => count($meiliIndexes)],
            ['Pixie-format indexes' => count($pixieIndexes)],
            ['Unknown format indexes' => count($unknownIndexes)],
            ['Discovered/Updated' => $stats['discovered']],
            ['Created owners' => $stats['created']],
            ['Skipped' => $stats['skipped']],
            ['Pruned records' => $stats['pruned']],
        );

        if ($dryRun) {
            $io->success('Dry run completed - no changes were made');
        } else {
            $io->success('Index sync completed');
        }

        return Command::SUCCESS;
    }

    private function getMeilisearchIndexes(): array
    {
        try {
            $response = $this->meilisearchClient->listIndexesFast();
            return $response['results'] ?? [];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to fetch indexes from Meilisearch: ' . $e->getMessage());
        }
    }

    private function parseIndexDate(?string $dateString): ?\DateTime
    {
        if (!$dateString) {
            return null;
        }

        try {
            return new \DateTime($dateString);
        } catch (\Exception) {
            return null;
        }
    }

    private function pruneOrphanedRecords(SymfonyStyle $io, array $activeIndexes, bool $dryRun, ?string $pixieCode): int
    {
        $io->section('Checking for orphaned OwnerIndex records');

        // This would need to be implemented by querying your OwnerIndex repository
        // and comparing with the active indexes found in Meilisearch

        $pruned = 0;

        // Example implementation:
        // $allOwnerIndexes = $this->ownerIndexRepository->findAll();
        // $activeIndexNames = array_column($activeIndexes, 'indexName');
        //
        // foreach ($allOwnerIndexes as $ownerIndex) {
        //     if (!in_array($ownerIndex->getId(), $activeIndexNames)) {
        //         if ($pixieCode && $ownerIndex->getOwner()->getPixieCode() !== $pixieCode) {
        //             continue;
        //         }
        //
        //         $io->writeln("  Pruning orphaned record: " . $ownerIndex->getId());
        //         if (!$dryRun) {
        //             $this->entityManager->remove($ownerIndex);
        //             $pruned++;
        //         }
        //     }
        // }
        //
        // if (!$dryRun && $pruned > 0) {
        //     $this->entityManager->flush();
        // }

        return $pruned;
    }
}