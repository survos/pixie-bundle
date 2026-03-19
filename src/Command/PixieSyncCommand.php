<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

#[AsCommand('pixie:sync', 'Upload/download directories (as zip) to/from bunny')]
final class PixieSyncCommand
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly PixieService   $pixieService,
        // BunnyService injected optionally — not a hard dependency
        private readonly mixed          $bunnyService = null,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument] ?string $configCode = null,
        #[Argument('comma-delimited dirs (raw,json,pixie)')] string $dirs = 'json',
        #[Option('upload to bunny')]   bool $upload   = false,
        #[Option('download from bunny')] bool $download = false,
    ): int {
        if ($this->bunnyService === null) {
            $io->error('BunnyService not available. Run: composer require survos/bunny-bundle');
            return Command::FAILURE;
        }

        if (!$upload && !$download) {
            $io->error('You must specify --upload or --download');
            return Command::FAILURE;
        }

        $configCode ??= getenv('PIXIE_CODE');
        $baseDir = $this->pixieService->getSourceFilesDir($configCode);
        $zipDir  = $this->pixieService->getDataRoot() . '/_zip';

        if (!is_dir($zipDir)) {
            mkdir($zipDir, 0777, true);
        }

        foreach (explode(',', $dirs) as $dir) {
            $zipFilename = "{$zipDir}/{$configCode}-{$dir}.zip";
            $localDir    = "{$baseDir}/{$dir}";

            if ($upload) {
                $io->writeln("Zipping {$localDir} → {$zipFilename}");
                $this->zip($localDir, $zipFilename, $io);
                // $this->bunnyService->uploadFile($zipFilename, ...);
                $io->note('BunnyService upload not yet wired — zip created at ' . $zipFilename);
            }

            if ($download) {
                $io->writeln("Unzipping {$zipFilename} → {$localDir}");
                $this->unzip($zipFilename, $localDir, $io);
            }
        }

        $io->success('pixie:sync success ' . $configCode);
        return Command::SUCCESS;
    }

    private function unzip(string $filename, string $dir, SymfonyStyle $io): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($filename)) {
            $zip->extractTo($dir);
            $zip->close();
        } else {
            $io->error("Unable to unzip {$filename} to {$dir}");
        }
    }

    private function zip(string $dir, string $filename, SymfonyStyle $io): void
    {
        if (file_exists($filename)) {
            unlink($filename);
        }

        $zip    = new \ZipArchive();
        $result = $zip->open($filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($result !== true) {
            $io->error("Failed to create zip (code {$result}): {$filename}");
            return;
        }

        $finder = new Finder();
        $finder->files()->in($dir);
        $rows = [];

        foreach ($finder as $file) {
            $zip->addFile($file->getRealPath(), $file->getRelativePathname());
            $rows[] = [$file->getRelativePathname(), $file->getSize()];
        }

        $zip->close();

        $io->table(['File', 'Size'], $rows);
        $io->writeln(sprintf('Written %d files to %s', count($rows), $filename));
    }
}
