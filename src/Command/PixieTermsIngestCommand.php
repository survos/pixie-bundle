<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\Lingua\Core\Identity\HashUtil;
use Survos\PixieBundle\Entity\Term;
use Survos\PixieBundle\Entity\TermSet;
use Survos\PixieBundle\Service\PixieConfigRegistry;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:terms:ingest', 'Ingest extracted _terms/*.jsonl into Pixie DB (term_set + term) and bind Babel strCodes')]
final class PixieTermsIngestCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly PixieConfigRegistry $registry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixie code')] string $pixieCode,
        #[Option('Directory containing per-set JSONL files (default: data/<pixie>/20_normalize/_terms)')] ?string $dir = null,
        #[Option('Source locale (default from pixie config)')] ?string $sourceLocale = null,
        #[Option('Batch flush size')] int $batch = 1000,
        #[Option('Limit lines per file (0 = all)')] int $limit = 0,
    ): int {
        $config = $this->registry->get($pixieCode);
        if (!$config) {
            $io->error("Unknown pixie '$pixieCode'.");
            return Command::FAILURE;
        }

        $sourceLocale ??= $config->babel?->source ?? 'en';
        $sourceLocale = HashUtil::normalizeLocale((string)$sourceLocale) ?: 'en';

        $base = rtrim((string)($config->dataDir ?? ("data/$pixieCode")), '/');
        $dir ??= "$base/20_normalize/_terms";

        if (!is_dir($dir)) {
            $io->error("Terms directory not found: $dir");
            return Command::FAILURE;
        }

        $ctx = $this->pixie->getReference($pixieCode);
        $em = $ctx->em;

        $io->title("Ingest terms: $pixieCode");
        $io->writeln("Dir: $dir");
        $io->writeln("Source locale: $sourceLocale");

        $files = glob(rtrim($dir, '/') . '/*.jsonl') ?: [];
        sort($files);

        if ($files === []) {
            $io->warning('No *.jsonl files found.');
            return Command::SUCCESS;
        }

        $i = 0;
        foreach ($files as $file) {
            $io->section(basename($file));
            $fh = fopen($file, 'rb');
            if (!$fh) {
                $io->warning("Cannot read $file");
                continue;
            }

            $lineNo = 0;
            while (($line = fgets($fh)) !== false) {
                $lineNo++;
                if ($limit > 0 && $lineNo > $limit) {
                    break;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $rec = json_decode($line, true);
                if (!is_array($rec)) {
                    continue;
                }

                $setId = trim((string)($rec['set'] ?? ''));
                $code  = trim((string)($rec['code'] ?? ''));
                $label = trim((string)($rec['label'] ?? ''));

                if ($setId === '' || $code === '' || $label === '') {
                    continue;
                }

                $count = isset($rec['count']) ? (int)$rec['count'] : null;

                /** @var TermSet|null $set */
                $set = $ctx->repo(TermSet::class)->find($setId);
                if (!$set) {
                    $set = new TermSet($setId);
                    $set->pixieCode = $pixieCode;
                    $set->sourceLocale = $sourceLocale;
                    $em->persist($set);
                }

                $termId = Term::makeId($setId, $code);

                /** @var Term|null $term */
                $term = $ctx->repo(Term::class)->find($termId);
                if (!$term) {
                    $term = new Term($set, $code);
                    $em->persist($term);
                }

                // update label + count
                $term->rawLabel = $label;
                $term->count = $count;

                // bind Babel code for label
                $strCode = HashUtil::calcSourceKey($label, $sourceLocale);
                $term->bindStrCode('label', $strCode);

                if ((++$i % $batch) === 0) {
                    $em->flush();
                    $em->clear();
                    // reattach ownerRef if needed by your ctx; safe to ignore here
                }
            }

            fclose($fh);
        }

        $em->flush();

        $io->success(sprintf('Done. Upserted ~%d term records (batch counter).', $i));
        return Command::SUCCESS;
    }
}
