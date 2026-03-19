<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Entity\Term;
use Survos\PixieBundle\Entity\TermSet;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:terms:show', 'Show terms for a term set (localized) as JSON')]
final class PixieTermsShowCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly LocaleContext $localeContext,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixie code')] string $pixieCode,
        #[Argument('term set id (e.g. cul, mat, theme)')] ?string $setId = null,
        #[Option('Locale (default: current or pixie source locale)')] ?string $locale = null,
        #[Option('Limit rows (0 = all)')] int $limit = 50,
        #[Option('Offset')] int $offset = 0,
        #[Option('Pretty JSON output')] bool $pretty = true,
        #[Option('Include strCodes map in output')] bool $includeCodes = false,
    ): int {
        $ctx = $this->pixie->getReference($pixieCode);

        /** @var list<TermSet> $sets */
        $sets = $ctx->repo(TermSet::class)->findBy([], ['id' => 'ASC']);
        if ($sets === []) {
            $io->warning("No term sets found for $pixieCode.");
            return Command::SUCCESS;
        }

        $setId ??= $sets[0]->id;

        /** @var TermSet|null $set */
        $set = $ctx->repo(TermSet::class)->find($setId);
        if (!$set) {
            $io->error("Term set not found: $setId");
            return Command::FAILURE;
        }

        // Determine locale: explicit option > current > pixie source locale > default
        $locale ??= $this->localeContext->get();
        $locale ??= $ctx->config->getSourceLocale($this->localeContext->getDefault());
        $locale ??= $this->localeContext->getDefault();

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $out = $this->localeContext->run($locale, function () use ($ctx, $setId, $limit, $offset, $includeCodes): array {
            /** @var list<Term> $terms */
            $terms = $ctx->repo(Term::class)->findBy(
                ['termSet' => $setId],
                ['code' => 'ASC'],
                $limit > 0 ? $limit : null,
                $offset
            );

            $rows = [];
            foreach ($terms as $t) {
                $row = [
                    'id' => $t->id,
                    'set' => $setId,
                    'code' => $t->code,
                    'label' => $t->label,
                    'rawLabel' => $t->rawLabel,
                    'count' => $t->count,
                ];

                if ($includeCodes) {
                    $row['strCodes'] = $t->getStrCodeMap();
                }

                $rows[] = $row;
            }

            return $rows;
        });

        $io->title(sprintf('Terms: %s set=%s locale=%s', $pixieCode, $setId, $locale));
        $io->writeln(json_encode($out, $flags) ?: '[]');

        return Command::SUCCESS;
    }
}
