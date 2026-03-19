<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand('pixie:make', 'Execute the build steps to create csv/json', aliases: ['pixie:build'])]
final class PixieMakeCommand
{
    public function __construct(
        private readonly LoggerInterface     $logger,
        private readonly PixieService        $pixieService,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument] ?string $configCode = null,
        #[Option('build from source')] bool $build = true,
        #[Option('make from /json')] bool $make = false,
        #[Option('dry run, just show commands')] bool $dry = false,
    ): int {
        $configCode ??= getenv('PIXIE_CODE');
        $config = $this->pixieService->getReference($configCode)->config;
        $source = $config->getSource();

        if ($build) {
            $this->process($source->build ?? [], $dry, $io);
        }
        if ($make) {
            $this->process($source->make ?? [], $dry, $io);
        }

        $io->success('pixie:make ' . $configCode);
        return Command::SUCCESS;
    }

    private function process(array $steps, bool $dry, SymfonyStyle $io): void
    {
        foreach ($steps as $step) {
            switch ($step['action']) {
                case 'fetch':
                    $io->writeln("fetching {$step['source']} to {$step['target']}");
                    if (!$dry) {
                        $this->fetch($step['source'], $step['target'], $io);
                    }
                    break;

                case 'bash':
                    $io->writeln("running {$step['cmd']}...");
                    if (!$dry) {
                        passthru($step['cmd']);
                    }
                    break;

                case 'unzip':
                    $zip = new \ZipArchive();
                    if (!$dry && $zip->open($step['source'])) {
                        $zip->extractTo($step['target']);
                        $zip->close();
                        $io->success(sprintf('%s extracted to %s', $step['source'], $step['target']));
                    } else {
                        $io->writeln(sprintf('  [dry] unzip %s → %s', $step['source'], $step['target']));
                    }
                    break;

                case 'cmd':
                    $io->writeln("running {$step['cmd']}...");
                    if (!$dry) {
                        passthru('bin/console ' . $step['cmd']);
                    }
                    break;

                default:
                    $io->warning('Unknown action: ' . $step['action']);
            }
        }
    }

    private function fetch(string $url, string $destination, SymfonyStyle $io): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0777, true);
        }
        if (str_ends_with($destination, '/')) {
            $destination .= pathinfo($url, PATHINFO_BASENAME);
        }
        if (file_exists($destination)) {
            $io->writeln("{$destination} already exists");
            return;
        }

        $io->writeln("Fetching {$url}...");
        $response   = $this->httpClient->request('GET', $url);
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200) {
            file_put_contents($destination, $response->getContent());
            $io->writeln("{$destination} written.");
        } else {
            $io->error("HTTP {$statusCode} fetching {$url}");
        }
    }
}
