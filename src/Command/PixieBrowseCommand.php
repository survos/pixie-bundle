<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\PixieDocumentProjector;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Preview what will be sent to Meili for a given core/locale.
 * Uses the same projector as indexing.
 */
#[AsCommand('pixie:browse', 'Preview populated, translated core rows as JSON (what will go to Meili)')]
final class PixieBrowseCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly PixieDocumentProjector $projector,
        private readonly LocaleContext $localeContext,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixieCode')] string $pixieCode,
        #[Argument('core')] string $core,
        #[Option('locale')] string $locale = 'en',
        #[Option('limit')] int $limit = 25,
        #[Option('offset')] int $offset = 0,
        #[Option('pretty')] bool $pretty = true,
        #[Option('Comma-separated list of within-core IDs to fetch')] ?string $ids = null,
    ): int {
        // Switch translation locale so post-load hooks resolve translated strings
        $this->localeContext->set($locale);
        $io->note('Locale set to ' . $this->localeContext->get());

        $ctx   = $this->pixie->getReference($pixieCode);
        $owner = $ctx->ownerRef;
        $coreEntity = $this->pixie->getCore($core, $owner);
        $rowRepo = $ctx->repo(Row::class);

        // Find rows
        if ($ids) {
            $rows = [];
            foreach (array_filter(array_map('trim', explode(',', $ids))) as $within) {
                $rowId = Row::RowIdentifier($coreEntity, $within);
                if ($r = $rowRepo->find($rowId)) {
                    $rows[] = $r;
                }
            }
        } else {
            $rows = $rowRepo->findBy(['core' => $coreEntity], ['id' => 'ASC'], $limit ?: null, $offset);
        }

        if (!$rows) {
            $io->writeln('[]');
            return 0;
        }

        // Print each projected document
        $jsonFlags = $pretty ? (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 0;
        foreach ($rows as $row) {
            $doc = $this->projector->project($ctx, $row, $locale);
            $io->writeln(json_encode($doc, $jsonFlags));
            if ($pretty) {
                $io->newLine();
            }
        }

        return Command::SUCCESS;
    }
}
