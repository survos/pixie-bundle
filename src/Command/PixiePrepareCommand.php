<?php

namespace Survos\PixieBundle\Command;

use JsonMachine\Items;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Event\ImportFileEvent;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieConvertService;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsCommand('pixie:legacy-prepare', 'process /raw to /json via the rules key')]
final class PixiePrepareCommand
{

    private bool $initialized = false;
    private ?ProgressBar $progressBar = null;
    private int $total = 0;
    private ?SymfonyStyle $io = null; // stored for use in event listeners

    public function __construct(
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag,
        private readonly PixieService $pixieService,
        private readonly PixieConvertService $pixieConvertService,
        private array                 $openFiles = [], // keep json files open, close at the end
    )
    {

    }

    public function __invoke(
        SymfonyStyle                                                                     $io,
        #[Argument('config code')] ?string                                               $configCode,
        #[Argument('sub code, e.g. musdig inst id')] ?string                             $subCode = null,
        #[Option('conf directory, default to directory name of first argument')] ?string $dir = null,
        #[Option("max number of records per table to import")] ?int                      $limit = null,
        #[Option("overwrite records if they already exist")] bool                        $overwrite = false,
        #[Option("index after import (default: true)")] ?bool                            $index = null,
        #[Option("purge db file first")] bool                                            $reset = false,
        #[Option("Batch size for writing JSON")] int                                     $batch = 500,
        #[Option("starting at (offset)", name: 'start')] int                             $startingAt = 0,
        #[Option("table search pattern")] string                                         $pattern = '',

    ): int
    {
        $configCode ??= getenv('PIXIE_CODE');
        $this->io = $io;
        $this->initialized = true;
        // not sure how to auto-wire this in the constructor
        // idea: if conf doesn't exist, require a directory name and create it, a la rector
//        if (empty($configCode)) {
//            $configCode = pathinfo($dir, PATHINFO_BASENAME);
//        }

        $pixieService = $this->pixieService;
        $pixieConvertService = $this->pixieConvertService;
        $index = is_null($index) ? true : $index;

        $ctx = $pixieService->getReference($configCode);
        $config = $ctx->config;


        $sourceDir = $pixieService->getSourceFilesDir($configCode, config: $config, subCode: $subCode);
//        dd($sourceDir);
        $rawDir = $sourceDir . "/raw";
        $jsonDir = $sourceDir . "/json";
        assert(file_exists($rawDir), "Missing $rawDir, run git clone or bunny download");
        assert(is_dir($sourceDir), "Invalid source dir: $sourceDir");


//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

//        if (!is_dir($dir)) {
//            $io->error("$dir does not exist.  set the directory in config or pass it as the first argument");
//            return Command::FAILURE;
//        }

//        if (!file_exists($configFilename) && (file_exists($configWithCsv = $dirOrFilename . "/$config"))) {
//            $config = $configWithCsv;
//        }
//
//        if (!file_exists($config) && (file_exists($configInPackages = $this->bag->get('kernel.project_dir') . "/config/packages/Entity/$config"))) {
//            $config = $configInPackages;
//        }
//
//        if (!file_exists($config)) {
//            $config = $dirOrFilename . "/$config";
//        }

        $pixieDbName = $pixieService->getPixieFilename($configCode, $subCode);

        if (!file_exists($dirName = pathinfo($pixieDbName, PATHINFO_DIRNAME))) {
            mkdir($dirName, 0777, true);
        }
        $io->title(sprintf("Reading %s, writing %s", $rawDir, $jsonDir));

        if ($limit && !$this->total) {
            $this->total = $limit;
        }
        # eh??
//        if ($config->getSource()->total) {
//            $this->total = $config->getSource()->total;
//        }

        $explodeRules = [];
        foreach ($config->getTables() as $tableName => $table) {
            foreach ($table->getRules() as $rule) {
                // rules? or properties?  This is before properties are set.
                $lastChar = $rule[-1];
                if (in_array($lastChar, [',', '|', ';'])) {
                    $fieldName = str_replace($lastChar, '', $rule);
                    $explodeRules[$tableName][$fieldName] = $lastChar;
                }
            }
        }
        $limit = $this->pixieService->getLimit($limit);
        $this->progressBar = new ProgressBar($io, $limit);

        $pixieConvertService->convert($configCode, $subCode, config: $config, limit: $limit, startingAt: $startingAt,
//            context: [
//                'tags' => $tags ? explode(",", $tags) : [],
//            ],
            overwrite: $overwrite,
            pattern: $pattern,
            callback: function ($row, $idx, Config $config, string $tableName)
            use ($batch, $limit, $jsonDir, $explodeRules) {
                if (!$openFile = $this->openFiles[$tableName] ?? false) {
                    if (!file_exists($jsonDir)) {
                        mkdir($jsonDir, 0777, true);
                    }
                    $stream = fopen($jsonFilename = $jsonDir . "/$tableName.json", "w");
                    fwrite($stream, "[\n");
                    $openFile = [
                        'stream' => $stream,
                        'fileName' => $jsonFilename,
                        'fields' => [],
                        'count' => 0];
                    $this->openFiles[$tableName] = $openFile;
                }
                $this->progressBar->advance();

                // we need to know the fields that are being used
                foreach ($row as $var => $val) {
                    if (empty($val)) {
                        unset($row[$var]);
                    } else {
                        if ($explode = $explodeRules[$tableName][$var] ?? false) {
                            $row[$var] = array_map(
                                fn($x) => ctype_digit(trim($x)) ?
                                    (int)$x :
                                    trim($x),
                                explode($explode, (string)$row[$var]));
                        }
                        if (!array_key_exists($var, $this->openFiles[$tableName]['fields'])) {
                            $this->openFiles[$tableName]['fields'][$var] = 0;
                        }
                        $this->openFiles[$tableName]['fields'][$var]++;
                    }
                }
//                dump($row, $openFile['fileName']);

                fwrite($openFile['stream'],
                    ($openFile['count'] ? ',' : '') .
                    json_encode($row, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE));
                $this->openFiles[$tableName]['count'] += 1;

                $finished = $limit ? $idx >= ($limit) : false;
                return $limit ? !$finished : true; // break if we've hit the limit.
            });
        // close and report.
        $this->progressBar->finish();

        $consoleTable = new Table($io);
        $consoleTable->setHeaders(['table', 'count']);
        $filesData = [];
        foreach ($this->openFiles as $openFile) {
            fwrite($openFile['stream'], "\n]\n");
            fclose($openFile['stream']);

            $filesData[] = [
                'name' => $openFile['fileName'],
                'count' => $openFile['count'],
                'fields' => $openFile['fields']
            ];
            $consoleTable->addRow([$openFile['fileName'], $openFile['count']]);
        }

        file_put_contents($filesFilename = $jsonDir . "/_files.json", json_encode($filesData,
            JSON_UNESCAPED_SLASHES +
            JSON_UNESCAPED_UNICODE +
            JSON_PRETTY_PRINT));

        $consoleTable->render();
        $io->success(self::class . " success.\n$filesFilename");

        return Command::SUCCESS;
    }


    #[AsEventListener(event: ImportFileEvent::class)]
    public function startImport(ImportFileEvent $event): void
    {
        if (!$this->initialized) {
            return;
        }
        $this->io->title(sprintf("%s -> %s", $event->filename, $event->pixieDbName));
        if (!$count = $this->total) {
            if ($event->getType() == 'json') {
                // faster to get the first record and filesize and divide, for a rough estimate.
                $iterator = Items::fromFile($event->filename)->getIterator();
                $first = $iterator->current();
//                $pointer = $iterator->getCurrentJsonPointer();
                $size = filesize($event->filename);

                $guess = (int)($size / strlen(json_encode($first, JSON_PRETTY_PRINT)));
                $count = $guess;
                assert($guess, "no objects in $event->filename");
                $iterator->rewind();

//                dd("plz set total in config");
//                    halaxa/json-machine
//                try {
//                    $count =  iterator_count(Items::fromFile($event->filename));
//                    dd($count, $guess);
//                } catch (\Exception $e) {
//                    $this->logger->error($e->getMessage() . "\n" . $event->filename);
//                    throw $e;
//                }
            } else {
                $count = $this->lineCount($event->filename);
            }
        }

        $this->progressBar = new ProgressBar($this->io->getOutput(), $count);
//        $this->progressBar->start($count);
    }

    private function lineCount(string $filename)
    {
//        return count(file($filename));
        $lines_command = sprintf('wc -l %s', $filename);
        $lines = system($lines_command);
        return (int)$lines;
    }


    #[AsEventListener(event: RowEvent::class)]
    public function importRow(RowEvent $event): void
    {
        switch ($event->type) {
            case $event::PRE_LOAD:
                break;
            case $event::LOAD:
                $this->progressBar?->advance();
                break;
            case $event::POST_LOAD:
                $this->progressBar?->finish();
                break;
        }
//        $this->initialized && $event->isRowLoad() && $this->progressBar->advance();
    }

    public function runImport(string $pixieCode, ?string $subCode = null): void
    {
        $cli = "pixie:import $pixieCode $subCode";
        $io->warning('bin/console ' . $cli);
        $this->runCommand($cli);
    }

}
