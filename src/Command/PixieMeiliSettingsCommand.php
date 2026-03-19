<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\MeiliBundle\Service\IndexNameResolver as MeiliIndexNameResolver;
use Survos\PixieBundle\Service\MeiliIndexer;
use Survos\PixieBundle\Service\MeiliSettingsBuilder;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:meili:settings', 'Build (and optionally apply) Meili settings from DTO mappers')]
final class PixieMeiliSettingsCommand extends Command
{
    public function __construct(
        private readonly PixieService $pixies,
        private readonly MeiliSettingsBuilder $builder,
        private readonly MeiliIndexer $indexer,
        private readonly MeiliIndexNameResolver $indexNames,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Pixie base name (e.g. larco)')] string $pixieCode,
        #[Option(description: 'Core code (optional; settings should typically be core-agnostic)')] ?string $core = null,
        #[Option(description: 'Locale (affects UID); defaults to source locale')] ?string $locale = null,
        #[Option(description: 'Apply to Meili index')] bool $apply = false,
    ): int {
        // Try to get locale from pixie DB; fall back to 'en' if DB doesn't exist yet.
        $fallbackSource = 'en';
        try {
            $ctx = $this->pixies->getReference($pixieCode);
            $fallbackSource = $ctx->ownerRef->locale
                ?? $ctx->config->sourceLocale
                ?? 'en';
        } catch (\Throwable) {
            $io->note(sprintf('Pixie DB not found for "%s" — using locale "%s".', $pixieCode, $fallbackSource));
        }

        $locale ??= $fallbackSource;

        $settings = $this->builder->build($pixieCode, $core);

        if (empty($settings['filterableAttributes']) && empty($settings['searchableAttributes'])) {
            $io->warning(sprintf('No DTO mappers found for "%s". Register a DTO class with #[Mapper(when: [\'%s\'])].', $pixieCode, $pixieCode));
            return Command::FAILURE;
        }

        $isMlFor = $this->indexNames->isMultiLingualFor($pixieCode, $fallbackSource);
        $uid = $this->indexNames->uidFor($pixieCode, $locale, $isMlFor);

        $io->title(sprintf('Meili settings for %s', $uid));
        $io->writeln(json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($apply) {
            $this->indexer->ensureIndexReady($pixieCode, $locale, $core, 'id', $fallbackSource);
            $io->success("Ensured index + applied settings for $uid");
        } else {
            $io->note('Run with --apply to push these settings to Meili.');
        }

        return Command::SUCCESS;
    }
}
