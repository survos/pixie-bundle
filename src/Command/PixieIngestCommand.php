<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\Lingua\Core\Identity\HashUtil;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Import\Ingest\JsonIngestor;
use Survos\PixieBundle\Service\DtoMapper;
use Survos\PixieBundle\Service\DtoRegistry;
use Survos\PixieBundle\Service\PixieConfigRegistry;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\StatsCollector;
use Survos\DataBundle\Meta\DatasetMetadataLoader;
use Survos\DataBundle\Service\DataPaths;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('pixie:ingest', 'Import JSONL into Rows (file or directory).')]
final readonly class PixieIngestCommand
{
    public function __construct(
        private PixieService $pixie,
        private PixieConfigRegistry $pixieRegistry,
        private JsonIngestor $json,
        private DtoMapper $dtoMapper,
        private ?DtoRegistry $dtoRegistry,
        private StatsCollector $statsCollector,
        private ?DataPaths $dataPaths = null,
        private ?DatasetMetadataLoader $datasetMetadataLoader = null,
        #[Autowire('%kernel.environment%')] private ?string $env=null,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pixie code (e.g., larco, immigration)')]
        ?string $pixieCode = null,
        #[Option('Path to a *.jsonl file')]
        ?string $file = null,
        #[Option('Directory with *.jsonl (and/or *.ndjson)')]
        ?string $dir = null,
        #[Option('Core code to import into')]
        string $core = 'obj',
        #[Option('Primary key in the source record')]
        string $pk = 'id',
        #[Option(name: 'label-field', description: 'Fallback label field in the raw source')]
        ?string $labelField = null,
        #[Option('DTO FQCN to normalize rows (overrides auto)')]
        ?string $dto = null,
        #[Option(name: 'dto-auto', description: 'Auto-select DTO from registry')]
        bool $dtoAuto = true,
        #[Option('Flush batch size')]
        int $batch = 1000,
        #[Option('Max records per file (0 = all)')]
        ?int $limit = null,
        #[Option('Print a before/after sample row for mapping')]
        ?bool $dump = null,
        #[Option(name: 'dto-class', description: 'Alias of --dto (back-compat)')]
        ?string $dtoClass = null,
    ): int {
        $pixieCode ??= getenv('PIXIE_CODE');
        if (!$pixieCode) {
            $choices = $this->pixieRegistry->codes();
            $pixieCode = $io->askQuestion(new ChoiceQuestion("Pixie code?", $choices));
        }

        $isDev = $this->env === 'dev';
        $limit ??= $isDev ? 50 : 0;
        $dto = $dto ?? $dtoClass;

        $ctx = $this->pixie->getReference($pixieCode, requireConfig: false);
        $config = $ctx->config;

        // Detect PK from config rules if possible
        $actualPk = $this->determinePrimaryKey($config, $core, $pk);
        if ($actualPk !== $pk) {
            $io->writeln("Primary key detected from config: '$pk' -> '$actualPk'");
            $pk = $actualPk;
        }

        if ($dto && !\class_exists($dto)) {
            $io->error("DTO class not found: $dto");
            return Command::FAILURE;
        }

        $em = $ctx->em;

        // Resolve JSONL inputs
        $files = $this->resolveJsonlFiles($pixieCode, $file, $dir);
        if ($files === []) {
            $io->warning('No JSONL files found.');
            return Command::SUCCESS;
        }

        $io->title(sprintf(
            'Ingest %s core=%s pk=%s dto=%s auto=%s',
            $pixieCode,
            $core,
            $pk,
            $dto ?? 'none',
            $dtoAuto ? 'yes' : 'no'
        ));

        // Ensure Core exists and capture ids to reattach after clear()
        $ownerRef = $ctx->ownerRef ?? $em->getReference(Inst::class, $pixieCode);
        $coreEntity = $this->pixie->getCore($core, $ownerRef);
        $coreId = $coreEntity->id ?? $coreEntity->getId();
        $ownerId = $pixieCode;

        $sourceLocale = HashUtil::normalizeLocale((string) ($config->getSourceLocale() ?? 'en'));
        $metaLocale = $this->resolveDatasetLocale($pixieCode);
        if ($metaLocale !== null && $metaLocale !== '') {
            $sourceLocale = HashUtil::normalizeLocale($metaLocale);
        }
        if ($sourceLocale === '') {
            $sourceLocale = 'en';
        }

        $table = $config->getTable($core);
        $translatableFields = $table?->getTranslatable() ?? [];

        $seen = [];
        $total = 0;
        $unchanged = 0;
        $printedDebug = false;

        $rowRepo = $ctx->repo(Row::class);
        foreach ($files as $path) {
            $i = 0;
            $io->section(basename($path));

            if (!is_readable($path)) {
                $io->error("File not readable: $path");
                continue;
            }

            $fileSize = filesize($path);
            $io->writeln('File size: ' . number_format((int) $fileSize) . ' bytes');
            if (!$fileSize) {
                $io->warning("Empty file, skipping: $path");
                continue;
            }

            try {
                // JsonIngestor::iterate() already supports jsonl line iteration
                $iter = $this->json->iterate($path);
            } catch (\Throwable $e) {
                $io->error("Failed to create iterator for $path: " . $e->getMessage());
                continue;
            }

            // We can’t know max without a profile; still show progress for the file.
            $pb = $io->createProgressBar(0);
            $pb->setFormat(' %current% [%bar%] %message%');
            $pb->setMessage(basename($path));
            $pb->start();

            $recordCount = 0;
            $skippedCount = 0;

            foreach ($iter as $record) {
                $recordCount++;

                if (!\is_array($record)) {
                    $skippedCount++;
                    continue;
                }

                $idWithinCore = (string) ($record[$pk] ?? '');
                if ($idWithinCore === '') {
                    $skippedCount++;
                    continue;
                }

                if (isset($seen[$idWithinCore])) {
                    $skippedCount++;
                    continue;
                }
                $seen[$idWithinCore] = true;

                // Choose DTO
                $chosen = $dto;
                if (!$chosen && $dtoAuto && $this->dtoRegistry) {
                    $sel = $this->dtoRegistry->select($pixieCode, $core, $record);
                    $chosen = $sel['class'] ?? null;
                }

                // Apply DTO mapping
                $normalized = $record;


                if ($chosen) {
                    try {
                        $dtoObj = $this->dtoMapper->mapRecord($record, $chosen, ['pixie' => $pixieCode, 'core' => $core]);
                        $normalized = $this->dtoMapper->toArray($dtoObj);
                        $this->statsCollector->accumulate($pixieCode, $core, $normalized, $chosen);
                    } catch (\Throwable $e) {
                        $io->warning("DTO mapping failed for id=$idWithinCore: " . $e->getMessage());
                        // fallback to raw record
                        $normalized = $record;
                    }
                }

                if ($normalized === $record) {
                    $unchanged++;
                    if ($dump && !$printedDebug) {
                        $io->note("Mapping produced no changes for id=$idWithinCore (DTO=" . ($chosen ?? 'none') . ')');
                        $io->writeln('RAW:  ' . json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $io->writeln('NORM: ' . json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $printedDebug = true;
                    }
                } elseif ($dump && !$printedDebug) {
                    $io->success("Mapped id=$idWithinCore with DTO " . ($chosen ?? 'none'));
                    $io->writeln('RAW:  ' . json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $io->writeln('NORM: ' . json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $printedDebug = true;
                }

                // Upsert Row
                $rowId = Row::rowIdentifier($coreEntity, $idWithinCore);

                /** @var Row|null $row */
                $row = $rowRepo->find($rowId);
                if (!$row) {
                    $row = new Row($coreEntity, $idWithinCore);
                    $ctx->persist($row);
                }

                // Label fallback
                $label = $normalized['label'] ?? ($normalized['name'] ?? ($normalized['title'] ?? null));
                if (!$label && $labelField && isset($record[$labelField])) {
                    $label = (string) $record[$labelField];
                }
                $row->setLabel((string) ($label ?: "row $core:$idWithinCore"));

                // Store raw + normalized
                $row->raw = $record;
                $row->setData($normalized);

                // Populate Str codes during ingest (no separate translate step)
                if ($translatableFields !== []) {
                    foreach ($translatableFields as $field) {
                        $field = trim((string) $field);
                        if ($field === '') {
                            continue;
                        }

                        $text = $field === 'label'
                            ? (string) $row->rawLabel
                            : (string) (($row->data ?? [])[$field] ?? '');

                        $text = trim($text);
                        if ($text === '') {
                            continue;
                        }

                        $code = HashUtil::calcSourceKey($text, $sourceLocale);
                        $row->bindStrCode($field, $code);
                    }
                }

                if ((++$i % $batch) === 0) {
                    $em->flush();
                    $em->clear(); // ORM 3: full clear; reattach

                    $ownerRef = $em->getReference(Inst::class, $ownerId);
                    $coreEntity = $em->getReference(Core::class, $coreId);
//                    $ctx->ownerRef = $ownerRef;

                    $this->statsCollector->flush($em);
                }

                if ($limit && $i >= $limit) {
                    break;
                }

                $pb->advance();
            }

            $pb->finish();
            $io->newLine(2);
            $ctx->flush();
            $total += $i;

            $io->writeln(sprintf(
                'Processed %d records, imported %d, skipped %d from %s',
                $recordCount,
                $i,
                $skippedCount,
                basename($path)
            ));
        }

        if ($unchanged > 0) {
            $io->note("$unchanged record(s) were unchanged by mapping (still raw keys). Did you pass --dto or enable --dto-auto?");
        }
        $actual = $rowRepo->count();

        $io->success("Done. Imported $total rows to core=$core, now at $actual rows.");
        return Command::SUCCESS;
    }

    /**
     * Resolve JSONL files.
     *
     * Rules:
     * - If --file is passed: it must end with .jsonl or .ndjson
     * - Otherwise scan --dir (or default) for *.jsonl and *.ndjson
     *
     * @return list<string>
     */
    private function resolveJsonlFiles(string $pixieCode, ?string $file, ?string $dir): array
    {
        $isJsonl = static fn(string $p): bool => (bool) preg_match('/\.(jsonl|ndjson)$/i', $p);

        if ($file) {
            if (!$isJsonl($file)) {
                throw new \RuntimeException("pixie:ingest expects JSONL only. File must end with .jsonl or .ndjson: $file");
            }
            return [$file];
        }

        if ($dir === null) {
            $datasetPath = $this->resolveDatasetJsonlPath($pixieCode);
            if ($datasetPath !== null) {
                return [$datasetPath];
            }
        }

        $dir ??= getcwd() . "/data/$pixieCode/json";
        if (!is_dir($dir)) {
            throw new \RuntimeException("Directory not found: $dir (pass --file or --dir)");
        }

        $files = array_merge(
            glob(rtrim($dir, '/') . '/*.jsonl') ?: [],
            glob(rtrim($dir, '/') . '/*.ndjson') ?: [],
        );

        sort($files);

        // Filter out metadata files (and anything else you don't want)
        $files = array_values(array_filter($files, static fn(string $f): bool => basename($f) !== '_files.json'));

        return $files;
    }

    private function resolveDatasetJsonlPath(string $pixieCode): ?string
    {
        if ($this->dataPaths === null) {
            return null;
        }

        $datasetKey = $this->dataPaths->sanitizeDatasetKey($pixieCode);
        $normalizeDir = $this->dataPaths->stageDir($datasetKey, 'normalize');
        $candidate = $normalizeDir . '/' . $this->dataPaths->defaultObjectFilename;

        if (is_file($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function resolveDatasetLocale(string $pixieCode): ?string
    {
        if ($this->dataPaths === null || $this->datasetMetadataLoader === null) {
            return null;
        }

        $datasetKey = $this->dataPaths->sanitizeDatasetKey($pixieCode);
        $metaDir = $this->dataPaths->stageDir($datasetKey, 'meta');
        $metaFile = $metaDir . '/dataset.yaml';
        if (!is_file($metaFile)) {
            return null;
        }

        $meta = $this->datasetMetadataLoader->load($metaFile);
        $locale = $meta['locale']['default'] ?? null;
        return is_string($locale) ? $locale : null;
    }

    /**
     * Determine the actual primary key field from the pixie configuration rules.
     * Looks for a rule that maps some source field to 'id'.
     */
    private function determinePrimaryKey(mixed $config, string $core, string $fallbackPk): string
    {
        try {
            if (!method_exists($config, 'getTables')) {
                return $fallbackPk;
            }

            $tables = $config->getTables();
            if (!isset($tables[$core])) {
                return $fallbackPk;
            }

            $table = $tables[$core];

            $rules = null;
            if (method_exists($table, 'getRules')) {
                $rules = $table->getRules();
            } elseif (property_exists($table, 'rules')) {
                $rules = $table->rules;
            } elseif (is_array($table) && isset($table['rules'])) {
                $rules = $table['rules'];
            }

            if (!$rules) {
                return $fallbackPk;
            }

            foreach ($rules as $sourceField => $targetField) {
                $cleanSourceField = trim((string) $sourceField, '/');
                $cleanTargetField = trim((string) $targetField);

                if ($cleanTargetField === 'id') {
                    return $cleanSourceField;
                }
            }

            return $fallbackPk;
        } catch (\Throwable) {
            return $fallbackPk;
        }
    }
}
