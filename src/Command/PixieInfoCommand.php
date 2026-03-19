<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Browse a pixie database from the CLI.
 *
 *   bin/console pixie:info fortepan_hu
 *   bin/console pixie:info fortepan_hu --rows=10
 *   bin/console pixie:info fortepan_hu --core=obj --rows=5
 */
#[AsCommand('pixie:info', 'Show info and sample rows from a pixie database')]
final class PixieInfoCommand extends PixieCommand
{
    public function __construct(PixieService $pixieService)
    {
        $this->pixieService = $pixieService;
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pixie code (e.g. fortepan_hu)')] ?string $pixieCode = null,
        #[Option('Core to browse')] string $core = 'obj',
        #[Option('Number of sample rows to show (0 = none)')] int $rows = 5,
        #[Option('Show full row data (not just label)')] bool $full = false,
    ): int {
        $pixieCode ??= getenv('PIXIE_CODE');
        if (!$pixieCode) {
            // List all available pixies
            return $this->listPixies($io);
        }

        $ctx = $this->pixieService->getReference($pixieCode);
        $em  = $ctx->em;

        $dbFile = $this->pixieService->getPixieFilename($pixieCode);
        $dbSize = is_file($dbFile) ? round(filesize($dbFile) / 1024) . ' KB' : 'NOT FOUND';

        $io->title(sprintf('Pixie: %s', $pixieCode));

        /** @var Owner|null $owner */
        $owner = $em->getRepository(Inst::class)->find($pixieCode);

        $io->table(['Field', 'Value'], [
            ['Pixie code',  $pixieCode],
            ['DB path',     $dbFile],
            ['DB size',     $dbSize],
            ['Label',       $owner?->name ?? '(no owner)'],
            ['Locale',      $owner?->locale ?? '-'],
        ]);

        // Cores + row counts
        $cores = $em->getRepository(Core::class)->findAll();
        if ($cores) {
            $coreRows = [];
            foreach ($cores as $c) {
                $count = $em->getRepository(Row::class)->count(['core' => $c]);
                $coreRows[] = [$c->code, number_format($count)];
            }
            $io->section('Cores');
            $io->table(['Core', 'Rows'], $coreRows);
        } else {
            $io->note('No cores — run pixie:migrate first.');
            return Command::SUCCESS;
        }

        // Sample rows
        if ($rows > 0) {
            $coreEntity = $em->getRepository(Core::class)->findOneBy(['code' => $core]);
            if (!$coreEntity) {
                $io->warning("Core '{$core}' not found in this pixie.");
                return Command::SUCCESS;
            }

            $sample = $em->getRepository(Row::class)->findBy(['core' => $coreEntity], [], $rows);
            if ($sample) {
                $io->section(sprintf("Sample rows (core=%s, limit=%d)", $core, $rows));
                foreach ($sample as $row) {
                    $data = $full ? $row->getData() : [
                        'id'    => $row->idWithinCore,
                        'label' => $row->rawLabel,
                    ];
                    $io->definitionList(...array_map(
                        static fn($k, $v) => [$k => is_array($v) ? implode(', ', array_slice((array)$v, 0, 3)) : (string)$v],
                        array_keys($data), array_values($data)
                    ));
                }
            }
        }

        $io->note([
            'Browse on the web:',
            sprintf('  https://mus.wip/pixie/%s', $pixieCode),
        ]);

        return Command::SUCCESS;
    }

    private function listPixies(SymfonyStyle $io): int
    {
        $io->title('Available Pixie Databases');

        $dbDir = $this->pixieService->getPixieDbDir();
        $files = glob($dbDir . '/*.db') ?: [];

        if (empty($files)) {
            $io->warning("No .db files found in {$dbDir}");
            $io->note('Run: bin/console pixie:migrate --provider=<aggregator>');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($files as $file) {
            $code    = basename($file, '.db');
            $size    = round(filesize($file) / 1024) . ' KB';
            $rows[]  = [$code, $size, date('Y-m-d H:i', filemtime($file))];
        }

        $io->table(['Pixie code', 'Size', 'Modified'], $rows);
        $io->note(sprintf('%d pixie databases in %s', count($rows), $dbDir));
        $io->text('Inspect one: bin/console pixie:info <pixie-code>');

        return Command::SUCCESS;
    }
}
