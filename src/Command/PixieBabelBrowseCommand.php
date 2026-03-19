<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Service\PixieBabelBrowser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:babel:browse', 'Browse Babel translations used by a Pixie (via str_codes), without loading huge pages')]
final class PixieBabelBrowseCommand
{
    public function __construct(
        private readonly PixieBabelBrowser $browser,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pixie code (e.g. cleveland)')] string $pixieCode,
        #[Option('Table/core filter (if available in pixie_row).')] ?string $table = null,
        #[Option('Field filter (e.g. label, description).')] ?string $field = null,
        #[Option('Target locale to display (e.g. es).')] string $locale = 'es',
        #[Option('Limit number of pixie rows scanned (0 = no limit).')] int $limit = 0,
        #[Option('Show only missing translations.')] bool $missingOnly = false,
    ): int {
        $result = $this->browser->browse(
            pixieCode: $pixieCode,
            table: $table,
            field: $field,
            locale: $locale,
            limit: $limit,
            missingOnly: $missingOnly,
        );

        $rows = $result['rows'];
        $totals = $result['totals'];

        $io->title(sprintf('Pixie %s → Babel (locale=%s)', $pixieCode, $locale));
        if ($table) {
            $io->writeln(sprintf('Table/core: <info>%s</info>', $table));
        }
        if ($field) {
            $io->writeln(sprintf('Field: <info>%s</info>', $field));
        }

        $io->writeln(sprintf(
            'Codes: %d, OK: %d, Missing: %d',
            $totals['codes'],
            $totals['translated'],
            $totals['missing']
        ));
        $io->newLine();

        if ($rows === []) {
            $io->warning('No matching codes found.');
            return Command::SUCCESS;
        }

        $io->table(
            ['field', 'code', 'source', $locale, 'status'],
            array_map(fn(array $r) => [
                $r['field'],
                $r['code'],
                $this->truncate($r['source']),
                $this->truncate($r['translation']),
                $r['status'],
            ], $rows)
        );

        return Command::SUCCESS;
    }

    private function truncate(?string $s, int $max = 60): string
    {
        if (!$s) {
            return '';
        }
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
