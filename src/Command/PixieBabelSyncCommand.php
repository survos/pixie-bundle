<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand('pixie:babel:sync', 'Push then pull Babel translations for a pixie (targets from pixie config)')]
final class PixieBabelSyncCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly LocaleContext $locale,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixieCode')] ?string $pixieCode = null,

        #[Option('Override target locales (comma-separated). Default: pixie config babel.targetLocales (or enabled_locales minus sourceLocale).')]
        ?string $targets = null,

        #[Option('Override engine (e.g. libre, deepl).')]
        ?string $engine = null,

        #[Option('Batch size to pass through to babel:push/babel:pull when supported.')]
        ?int $batch = null,

        #[Option('Limit to pass through to babel:push/babel:pull when supported.')]
        ?int $limit = null,

        #[Option('Show command lines without running them.')]
        bool $dryRun = false,

        #[Option('Fail if push/pull returns non-zero.')]
        bool $strict = true,
    ): int {
        $pixieCode ??= getenv('PIXIE_CODE');
        if (!$pixieCode) {
            $io->error('Pass pixieCode or set PIXIE_CODE');
            return Command::FAILURE;
        }

        $ctx = $this->pixie->getReference($pixieCode);
        $config = $ctx->config;

        $sourceLocale = $config->getSourceLocale($this->locale->getDefault());
        $enabled = $this->locale->getEnabled();
        $defaultTargets = $config->getTargetLocales($enabled, $sourceLocale);

        $targetLocales = $targets
            ? array_values(array_filter(array_map('trim', explode(',', $targets))))
            : $defaultTargets;

        if ($targetLocales === []) {
            $io->warning(sprintf(
                'No targetLocales configured for pixie "%s". Nothing to push/pull. (sourceLocale=%s)',
                $pixieCode,
                $sourceLocale
            ));
            return Command::SUCCESS;
        }

        $io->title(sprintf('Pixie Babel sync: %s', $pixieCode));
        $io->writeln(sprintf('sourceLocale: <info>%s</info>', $sourceLocale));
        $io->writeln(sprintf('targetLocales: <info>%s</info>', implode(', ', $targetLocales)));
        if ($engine) {
            $io->writeln(sprintf('engine: <info>%s</info>', $engine));
        }

        $console = $this->projectDir.'/bin/console';
        if (!is_file($console)) {
            $io->error(sprintf('Console not found at %s', $console));
            return Command::FAILURE;
        }

        // Build push command
        $push = [$console, 'babel:push'];
        // Common option naming in your ecosystem is --targets=es,fr; adjust here if your babel:push uses a different option.
        $push[] = '--targets='.implode(',', $targetLocales);
        if ($engine) {
            $push[] = '--engine='.$engine;
        }
        if ($batch !== null) {
            $push[] = '--batch='.$batch;
        }
        if ($limit !== null) {
            $push[] = '--limit='.$limit;
        }

        // Build pull command
        $pull = [$console, 'babel:pull'];
        $pull[] = '--targets='.implode(',', $targetLocales);
        if ($engine) {
            $pull[] = '--engine='.$engine;
        }
        if ($batch !== null) {
            $pull[] = '--batch='.$batch;
        }
        if ($limit !== null) {
            $pull[] = '--limit='.$limit;
        }

        // Run them
        $io->section('babel:push');
        $rc = $this->runProcess($io, $push, $dryRun);
        if ($rc !== 0 && $strict) {
            return $rc;
        }

        $io->section('babel:pull');
        $rc = $this->runProcess($io, $pull, $dryRun);
        if ($rc !== 0 && $strict) {
            return $rc;
        }

        $io->success('Pixie Babel sync completed.');
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $cmd
     */
    private function runProcess(SymfonyStyle $io, array $cmd, bool $dryRun): int
    {
        $io->writeln('<comment>$ '.implode(' ', array_map($this->escape(...), $cmd)).'</comment>');
        if ($dryRun) {
            return 0;
        }

        $p = new Process($cmd, $this->projectDir);
        $p->setTimeout(null);
        $p->run(function (string $type, string $buffer) use ($io): void {
            // Stream verbatim
            $io->write($buffer);
        });

        return $p->getExitCode() ?? 1;
    }

    private function escape(string $s): string
    {
        return str_contains($s, ' ') ? escapeshellarg($s) : $s;
    }
}
