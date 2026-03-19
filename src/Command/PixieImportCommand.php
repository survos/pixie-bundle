<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Service\PixieImportOrchestrator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:import', 'Orchestrate dataset import: resolve paths, run import:convert, then build a Pixie index model from the profile.')]
final class PixieImportCommand
{
    public function __construct(
        private readonly PixieImportOrchestrator $orchestrator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Dataset key (e.g. "euro-00902")')]
        string $dataset,

        #[Option('Input path override (file or directory). If omitted, resolved from DataPaths + stage.')]
        ?string $input = null,

        #[Option('Stage directory under data/<dataset>/ (e.g. "10_extract", "20_normalize"). Used only when --input is omitted.')]
        string $stage = '10_extract',

        #[Option('Output JSONL path (defaults to data/<dataset>.jsonl or .jsonl.gz with --gzip).')]
        ?string $output = null,

        #[Option('Write gzipped output (.jsonl.gz). If --output is provided, it will not be altered.')]
        bool $gzip = false,

        #[Option('Max records to convert/profile.')]
        ?int $limit = null,

        #[Option('Additional tags (comma-separated) passed through to import:convert.', name: 'tags')]
        ?string $tags = null,

        #[Option('If input JSON has a root key containing the list (passed through to import:convert).')]
        ?string $rootKey = null,

        #[Option('If input is a ZIP file, extract only this internal path (passed through to import:convert).')]
        ?string $zipPath = null,

        #[Option('Only profile the resolved input (no conversion).')]
        bool $profileOnly = false,

        #[Option('Do not write the index model file (still runs convert/profile).')]
        bool $noWriteIndex = false,

        #[Option('Do not run import:convert; only attempt to read the expected profile and build index model.')]
        bool $skipConvert = false,

        #[Option('Print the resolved paths and command, but do not execute anything.')]
        bool $dryRun = false,
    ): int {
        $io->title('Pixie / Import');

        $result = $this->orchestrator->run(
            io: $io,
            dataset: $dataset,
            inputOverride: $input,
            stage: $stage,
            outputOverride: $output,
            gzip: $gzip,
            limit: $limit,
            tags: $tags,
            rootKey: $rootKey,
            zipPath: $zipPath,
            profileOnly: $profileOnly,
            skipConvert: $skipConvert,
            writeIndex: !$noWriteIndex,
            dryRun: $dryRun,
        );

        if ($result->ok) {
            $io->success('Done.');
            return Command::SUCCESS;
        }

        $io->error($result->error ?? 'pixie:import failed.');
        return Command::FAILURE;
    }
}
