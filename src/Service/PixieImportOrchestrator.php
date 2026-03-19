<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\DataBundle\Service\DataPaths;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class PixieImportOrchestrator
{
    public function __construct(
        private readonly DataPaths $dataPaths,
        private readonly Filesystem $fs = new Filesystem(),
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
    ) {
    }

    public function run(
        SymfonyStyle $io,
        string $dataset,
        ?string $inputOverride,
        string $stage,
        ?string $outputOverride,
        bool $gzip,
        ?int $limit,
        ?string $tags,
        ?string $rootKey,
        ?string $zipPath,
        bool $profileOnly,
        bool $skipConvert,
        bool $writeIndex,
        bool $dryRun,
    ): PixieImportResult {
        $datasetKey = $this->normalizeDatasetKey($dataset);

        $input = $inputOverride ?? $this->resolveDatasetInput($datasetKey, $stage);

        if ($input === null) {
            return PixieImportResult::fail(sprintf(
                'Unable to resolve input for dataset "%s". Provide --input or ensure the stage exists under %s (e.g. %s/%s).',
                $datasetKey,
                $this->dataPaths->datasetsRoot,
                $this->dataPaths->datasetsRoot . '/' . $datasetKey,
                $stage
            ));
        }

        $output = $outputOverride ?? $this->defaultOutputPath($datasetKey, $gzip);

        // Stable profile naming regardless of .jsonl vs .jsonl.gz
        $profilePath = $this->stableProfilePath($output);

        $io->definitionList(
            ['APP_DATA_DIR' => $this->dataPaths->root],
            ['dataset' => $datasetKey],
            ['stage' => $stage],
            ['input' => $input],
            ['output' => $output],
            ['profile' => $profilePath],
            ['mode' => $profileOnly ? 'profile-only' : 'convert+profile'],
        );

        if (!$skipConvert) {
            $cmd = $this->buildImportConvertCommand(
                dataset: $datasetKey, // <-- IMPORTANT
                input: $input,
                output: $output,
                limit: $limit,
                tags: $tags,
                rootKey: $rootKey,
                zipPath: $zipPath,
                profileOnly: $profileOnly,
            );

            $io->section('Running import:convert');
            $io->writeln($this->formatCommandForShell($cmd));

            if ($dryRun) {
                $io->note('Dry-run: not executing import:convert.');
            } else {
                $process = new Process($cmd, $this->projectDir);
                $process->setTimeout(null);
                $process->run(function (string $type, string $buffer) use ($io): void {
                    $io->write($buffer);
                });

                if (!$process->isSuccessful()) {
                    return PixieImportResult::fail(sprintf(
                        'import:convert failed with exit code %s',
                        (string) $process->getExitCode()
                    ));
                }
            }
        } else {
            $io->note('Skipping import:convert (per --skip-convert).');
        }

        if (!$this->fs->exists($profilePath)) {
            return PixieImportResult::fail(sprintf(
                'Profile not found at "%s". Check output path and profile naming.',
                $profilePath
            ));
        }

        $io->section('Reading profile');
        $profile = $this->readJson($profilePath);
        if (!is_array($profile)) {
            return PixieImportResult::fail(sprintf('Profile JSON is invalid: "%s"', $profilePath));
        }

        $uniqueFields = array_values($profile['uniqueFields'] ?? []);
        $recordCount  = (int) ($profile['recordCount'] ?? 0);
        $fields       = $profile['fields'] ?? [];

        $io->definitionList(
            ['recordCount' => (string) $recordCount],
            ['uniqueFields' => $uniqueFields ? implode(', ', $uniqueFields) : '(none)'],
            ['fields' => is_array($fields) ? (string) count($fields) : '(unknown)'],
        );

        if ($writeIndex) {
            $io->section('Writing Pixie index model');

            $indexModel = [
                'dataset' => $datasetKey,
                'stage' => $stage,
                'input' => $profile['input'] ?? $input,
                'output' => $profile['output'] ?? $output,
                'recordCount' => $recordCount,
                'uniqueFields' => $uniqueFields,
                'tags' => array_values($profile['tags'] ?? []),
                'fields' => $fields,
                'profilePath' => $profilePath,
            ];

            $indexPath = $this->defaultIndexModelPath($datasetKey);
            $this->fs->mkdir(dirname($indexPath));
            file_put_contents($indexPath, json_encode($indexModel, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $io->success(sprintf('Index model written to %s', $indexPath));
        } else {
            $io->note('Not writing index model (per --no-write-index).');
        }

        return PixieImportResult::ok(input: $input, output: $output, profile: $profilePath);
    }

    private function normalizeDatasetKey(string $dataset): string
    {
        return strtolower($dataset);
    }

    private function resolveDatasetInput(string $datasetKey, string $stage): ?string
    {
        $stagePath = rtrim($this->dataPaths->datasetsRoot, '/') . '/' . $datasetKey . '/' . trim($stage, '/');
        if (is_dir($stagePath) || is_file($stagePath)) {
            return $stagePath;
        }

        $datasetRoot = rtrim($this->dataPaths->datasetsRoot, '/') . '/' . $datasetKey;
        if (is_dir($datasetRoot) || is_file($datasetRoot)) {
            return $datasetRoot;
        }

        return null;
    }

    private function defaultOutputPath(string $datasetKey, bool $gzip): string
    {
        $base = $this->projectDir . '/data/' . $datasetKey . '.jsonl';
        return $gzip ? $base . '.gz' : $base;
    }

    private function defaultIndexModelPath(string $datasetKey): string
    {
        return $this->projectDir . '/pixie/indexes/' . $datasetKey . '.index.json';
    }

    private function stableProfilePath(string $outputPath): string
    {
        $dir = dirname($outputPath);
        $name = basename($outputPath);

        if (str_ends_with($name, '.gz')) {
            $name = substr($name, 0, -3);
        }

        $name = preg_replace('/\.(jsonl|json)$/i', '', $name, 1) ?? $name;

        return $dir . '/' . $name . '.profile.json';
    }

    /**
     * Build: php bin/console import:convert <input> --output <output> --dataset <dataset> ...
     */
    private function buildImportConvertCommand(
        string $dataset,
        string $input,
        string $output,
        ?int $limit,
        ?string $tags,
        ?string $rootKey,
        ?string $zipPath,
        bool $profileOnly,
    ): array {
        $cmd = [
            'php',
            $this->projectDir . '/bin/console',
            'import:convert',
            $input,
            '--output',
            $output,
            '--dataset',
            $dataset, // <-- IMPORTANT
        ];

        if ($profileOnly) {
            $cmd[] = '--profile-only';
        }
        if ($limit !== null) {
            $cmd[] = '--limit';
            $cmd[] = (string) $limit;
        }
        if ($tags !== null && $tags !== '') {
            $cmd[] = '--tags';
            $cmd[] = $tags;
        }
        if ($rootKey !== null && $rootKey !== '') {
            $cmd[] = '--root-key';
            $cmd[] = $rootKey;
        }
        if ($zipPath !== null && $zipPath !== '') {
            $cmd[] = '--zip-path';
            $cmd[] = $zipPath;
        }

        return $cmd;
    }

    private function formatCommandForShell(array $cmd): string
    {
        return implode(' ', array_map(
            static fn(string $p) => str_contains($p, ' ') ? escapeshellarg($p) : $p,
            $cmd
        ));
    }

    private function readJson(string $path): mixed
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        return json_decode($raw, true);
    }
}
