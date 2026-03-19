<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\DataBundle\Service\DataPaths;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Service\PixieSchemaManager;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\SqlViewService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand('pixie:migrate', 'Create/update Pixie SQLite DB(s): schema alignment + owner/core rows (+ optional SQL views).')]
final class PixieMigrateCommand extends Command
{
    private DataPaths $dataPaths;

    public function __construct(
        private readonly PixieService $pixieService,
        private readonly PixieSchemaManager $schemaManager,
        private readonly SqlViewService $sqlViewService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[Required]
    public function setDataPaths(DataPaths $dataPaths): void
    {
        $this->dataPaths = $dataPaths;
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Pixie code (omit when using --all or --provider)')]
        ?string $pixieCodeFilter = null,

        #[Option('Process all pixies from config')]
        ?bool $all = null,

        #[Option('Process all datasets for a provider from APP_DATA_DIR (e.g. fortepan, dc, pp)')]
        ?string $provider = null,

        #[Option('Max pixies to process (0 = all)')]
        int $limit = 0,

        #[Option('Also (re)build SQL views (must not create tables)')]
        ?bool $views = null,

        #[Option('Also sync legacy Table rows (deprecated). Default: off.')]
        ?bool $legacyTables = null,
    ): int {
        if (!$pixieCodeFilter && !$all && !$provider) {
            $io->error('Pass a pixie code, --all, or --provider=<aggregator>.');
            return Command::FAILURE;
        }

        // Build the list of pixie codes to process
        $codes = [];

        if ($provider) {
            // Look up from DatasetInfo registry (populated by data:scan-datasets).
            // Falls back to filesystem scan if registry is empty — run data:scan-datasets first.
            $datasetInfoRepo = null;
            try {
                $datasetInfoRepo = $this->em->getRepository(\Survos\DataBundle\Entity\DatasetInfo::class);
                $infos = $datasetInfoRepo->findBy(['aggregator' => $provider]);
                foreach ($infos as $info) {
                    $codes[] = $info->pixieCode();
                    $codeToDatasetKey[$info->pixieCode()] = $info->datasetKey;
                }
            } catch (\Throwable) {
                // DatasetInfo table not yet created — fall back to filesystem scan
            }

            if (empty($codes)) {
                // Filesystem fallback — scan 00_meta/dataset.yaml files
                $io->note("DatasetInfo registry empty for '{$provider}' — scanning filesystem. Run data:scan-datasets to populate registry.");
                $providerDir = $this->dataPaths->datasetsRoot . '/' . $provider;
                foreach (glob($providerDir . '/*/00_meta/dataset.yaml') ?: [] as $metaFile) {
                    $meta = Yaml::parseFile($metaFile);
                    $datasetKey = $meta['dataset_key'] ?? null;
                    if ($datasetKey) {
                        $pixieCode = str_replace('/', '_', $datasetKey);
                        $codes[] = $pixieCode;
                        $codeToDatasetKey[$pixieCode] = $datasetKey;
                    }
                }
            }

            if (empty($codes)) {
                $io->warning("No datasets found for provider '{$provider}'");
                return Command::SUCCESS;
            }
            $io->text(sprintf('Found %d dataset(s) for provider <info>%s</info>', count($codes), $provider));
        } elseif ($pixieCodeFilter) {
            $codes[] = $pixieCodeFilter;
        } else {
            // --all: use YAML config as before
            $codes = array_keys($this->pixieService->getConfigFiles());
        }

        $processed = 0;

        // Map pixie codes back to dataset keys for provider-sourced codes
        $codeToDatasetKey = [];
        if ($provider) {
            $providerDir = $this->dataPaths->datasetsRoot . '/' . $provider;
            foreach (glob($providerDir . '/*/00_meta/dataset.yaml') ?: [] as $metaFile) {
                $meta = Yaml::parseFile($metaFile);
                $datasetKey = $meta['dataset_key'] ?? null;
                if ($datasetKey) {
                    $codeToDatasetKey[str_replace('/', '_', $datasetKey)] = $datasetKey;
                }
            }
        }

        foreach ($codes as $pixieCode) {
            $datasetKey = $codeToDatasetKey[$pixieCode] ?? null;
            // For provider-sourced codes, config may not exist in YAML — that's fine,
            // migrate just needs the code to resolve the db path and create the schema.
            $config = null;
            try {
                $configFiles = $this->pixieService->getConfigFiles(pixieCode: $pixieCode);
                $config = $configFiles[$pixieCode] ?? null;
            } catch (\Throwable) {
                // No YAML config — proceed with schema only
            }

            if ($all && $config && !$config->isMuseum()) {
                $io->writeln(sprintf('<comment>Skipping %s (not a museum)</comment>', $pixieCode));
                continue;
            }
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $label = $config?->source->label ?? $pixieCode;
            $io->section(sprintf('Migrating %s / %s', $pixieCode, $label));

            // 1) Point the shared pixie EM at the correct SQLite DB (creates file if missing)
            $em = $this->pixieService->switchToPixieDatabase($pixieCode);

            // 2) Ensure ORM schema exists (idempotent)
            $this->schemaManager->ensureOrmSchema($em);

            // 3) Align tenant schema to ORM metadata (safe/forward-only)
            // Skip if ensureOrmSchema just created the DB fresh — no diff needed.
            $tenantDbPath = $this->pixieService->getPixieFilename($pixieCode);
            try {
                $this->schemaManager->migrateTenantSchemaToOrm($config, $em, $tenantDbPath);
            } catch (\Doctrine\DBAL\Exception\TableNotFoundException $e) {
                // Fresh DB — ensureOrmSchema already created the correct schema, nothing to diff.
                $this->logger->info('migrateTenantSchemaToOrm skipped (fresh DB)', ['pixieCode' => $pixieCode]);
            }

            // 4) Optional: (re)build SQL views — only when config is available
            if ($views && $config) {
                try {
                    $ctx = $this->pixieService->getReference($pixieCode);
                    $this->sqlViewService->refreshViews($ctx);
                    if ($io->isVerbose()) {
                        $io->writeln('<info>Views refreshed.</info>');
                    }
                } catch (\Throwable $e) {
                    $io->warning('View refresh failed: ' . $e->getMessage());
                }
            }

            // 5) Owner + Core rows — always write Owner; Core rows only when config has tables
            $em->beginTransaction();
            try {
                $owner = $this->ensureOwner($em, $pixieCode);
                $owner->name      = $label;
                $owner->pixieCode = $pixieCode;
                $owner->locale    = $config?->getSourceLocale('en') ?? 'en';

                // Determine which cores to create:
                //   1. YAML config tables (existing behaviour)
                //   2. From profile files in APP_DATA_DIR when using --provider
                $ctx = $this->pixieService->getReference($pixieCode, requireConfig: false);
                $ctx->ownerRef = $em->getReference(Inst::class, $pixieCode);

                $coresToCreate = [];
                if ($config) {
                    $coresToCreate = array_keys($config->tables ?? []);
                }
                if (empty($coresToCreate) && $datasetKey !== null) {
                    // Derive cores from profile files: 21_profile/obj.profile.json → core "obj"
                    $profileDir = $this->dataPaths->datasetsRoot . '/' . $datasetKey . '/21_profile';
                    foreach (glob($profileDir . '/*.profile.json') ?: [] as $profileFile) {
                        $coreName = basename($profileFile, '.profile.json');
                        $coresToCreate[] = $coreName;
                    }
                    if (empty($coresToCreate)) {
                        // Fallback: always create 'obj' — the standard core name
                        $coresToCreate[] = 'obj';
                    }
                }

                foreach ($coresToCreate as $coreName) {
                    $core = $this->pixieService->getCoreInContext($ctx, $coreName, autoCreate: true);
                    \assert($em->contains($core));
                    if ($io->isVerbose()) {
                        $io->writeln(sprintf('  Core: <info>%s</info>', $coreName));
                    }
                }

                // Optional: legacy Table rows (deprecated)
                if ($legacyTables) {
                    $this->logger->warning('legacyTables requested for pixie:migrate (deprecated behavior)', [
                        'pixieCode' => $pixieCode,
                    ]);
                    // Intentionally not implemented: keep deprecated behavior isolated.
                }

                $em->flush();
                $em->commit();

                $repoCount = $em->getRepository(Core::class)->count();
                $io->success(sprintf(
                    '%s created/updated with %d cores (repo=%d)',
                    (string) $owner,
                    $owner->cores->count(),
                    $repoCount
                ));

                if ($io->isVerbose()) {
                    $this->logger->info('pixie:migrate completed', [
                        'pixieCode' => $pixieCode,
                        'cores' => $owner->cores->count(),
                        'db' => $tenantDbPath,
                    ]);
                }

                $processed++;
            } catch (\Throwable $e) {
                if ($em->getConnection()->isTransactionActive()) {
                    $em->rollback();
                }
                $io->error(sprintf('Failed to migrate %s: %s', $pixieCode, $e->getMessage()));
                $this->logger->error('pixie:migrate failed', [
                    'pixieCode' => $pixieCode,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return Command::SUCCESS;
    }

    private function ensureOwner(EntityManagerInterface $em, string $pixieCode): Inst
    {
        /** @var Owner|null $owner */
        $owner = $em->find(Inst::class, $pixieCode);
        if ($owner) {
            return $owner;
        }

        $owner = new Inst($pixieCode, $pixieCode);
        $em->persist($owner);
        $em->flush();

        return $owner;
    }
}
