<?php

namespace Survos\PixieBundle\Service;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use Survos\PixieBundle\Entity\OriginalImage;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Entity\RowImportState;
use Survos\PixieBundle\Model\OriginalImage as OriginalImageModel;
use Survos\PixieBundle\Model\PixieContext;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Instance;
use Survos\PixieBundle\Entity\Row;

//use Survos\PixieBundle\Entity\Str;
use Survos\PixieBundle\Entity\Str;
use Survos\PixieBundle\Repository\RowRepository;
use Survos\PixieBundle\Repository\StrRepository;
use Survos\PixieBundle\Repository\CoreRepository;
use Survos\PixieBundle\Repository\OriginalImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonMachine\Items;
use League\Csv\Info;
use League\Csv\Reader;
use League\Csv\SyntaxError;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\LibreTranslateBundle\Service\TranslationClientService;
use Survos\PixieBundle\Event\CsvHeaderEvent;
use Survos\PixieBundle\Event\ImportFileEvent;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Meta\PixieInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Table;
use Survos\PixieBundle\Model\Translation;
use Survos\PixieBundle\StorageBox;
use Survos\PixieBundle\Util\ImportUtil;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Finder\Finder;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use function Symfony\Component\String\u;


class PixieImportService
{
    // do NOT inject these, get them from the current EM
//    private RowRepository                     $rowRepository;

    public function __construct(
        private readonly PixieService             $pixieService,
        private readonly LoggerInterface          $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
//        #[Target('pixieEntityManager')]
//        private EntityManagerInterface            $entityManager,
        private RowIngestor $rowIngestor,
//private PixieEntityManagerProvider $provider,
//        private PropertyAccessorInterface         $propertyAccessor,
//        private ImportHandler                     $importHandler,
//        private CoreRepository                    $coreRepository,
//        private OriginalImageRepository $originalImageRepository,
//        private InstanceRepository                $instanceRepository,
//        private StrRepository                     $strRepository,
        private readonly SerializerInterface      $serializer,
        public bool                               $purgeBeforeImport = false,
        private array                             $listsByLabel = [],

        /** array<Core[]> */

    )
    {
    }

    public function import(string      $pixieCode,
                           ?string     $subCode,
                           ?Config     $config = null,
                           int         $limit = 0,
                           int         $startingAt = 0,
                           bool        $overwrite = false, // individual records
                           array       $context = [], // for passing extra context, like addTranslationstrings
                           ?StorageBox $kv = null, // if we already created it.
                           ?string     $pattern = null,
                           ?callable   $callback = null): StorageBox
    {

        $ctx = $this->pixieService->getReference($pixieCode);
        $strRepo = $ctx->repo(Str::class);
        $config = $ctx->config;
        $rowRepository = $ctx->repo(Row::class);
        $owner = $ctx->ownerRef;
        assert($owner, "Missing owner $pixieCode in PIXIE owner table");

        // the json files, slightly processed in :prepare, no csv
        $dirOrFilename = $this->pixieService->getSourceFilesDir($pixieCode, subCode: $subCode) . "/json";
        $counts = $this->getCounts($dirOrFilename);

        assert(file_exists($dirOrFilename), "Missing $dirOrFilename");
        $finder = new Finder();
        $files = $finder->in($dirOrFilename);
        if ($include = $config->getSource()->include) {
//            $files->name($include); // this filters out dir!
        }

        if ($ignore = $config->getIgnored()) {
            $files->notName($ignore);
        }
        $files->depth("<3");
        assert($files->count(), "No files (ignoring " . implode(',', $ignore) . ") in {$this->pixieService->getDataRoot()} $dirOrFilename");

        $fileMap = $this->getFileMap($files, $config);
        // still using ->map, need to move to config service
//        $kv = $this->createKv($fileMap, $config, $pixieCode, $subCode);

        $validTableNames = $config->getTables();
        // so that they're ordered as they are in the config, and coll and loc are loaded before obj

        // won't work when multiple files going to the same table, e.g. ahd
//        $filesByTablename = array_flip($fileMap);
        foreach ($fileMap as $realPath => $tableName) {
            $filesByTablename[$tableName][] = $realPath;
        }
//        dd($filesByTablename, $fileMap);

        foreach (array_values($config->getFiles()) as $tableName) {
            if ($pattern && !str_contains((string)$tableName, $pattern)) {
                continue;
            }


            $this->pixieService->getCore($tableName, $owner);
            SurvosUtils::assertKeyExists($tableName, $filesByTablename, "Missing table $tableName look for filename, not table");
            $filenames = $filesByTablename[$tableName];
            foreach ($filenames as $fn)
            {
                assert($tableName);
//                if (empty($tableName)) {
//                    $this->logger && $this->logger->warning("Skipping $fn, no map to tables");
//                    dd($fn, $tableName);
//                    continue;
//                }
//            dd($fn, $tableName, $fileMap);
//            $schemaTables = $kv->inspectSchema();
                if (!array_key_exists($tableName, $validTableNames)) {
                    $this->logger && $this->logger->warning("Skipping $fn, table is undefined");
//                dd($tableName, $kv->getFilename(), $validTableNames);
                    continue;
                }
                // we could do a callback here tagging it as a file.  Or some sort of event?
                $this->logger && $this->logger->warning("Importing $fn to $tableName");
                $this->eventDispatcher->dispatch(new ImportFileEvent($fn));

                $tables = $config->getTables(); // with the rules and such
                $table = $tables[$tableName];
                $pkName = $table->getPkName();
//                assert($pkName, "$tableName does not have a pk");
//                assert($pkName == $kv->getPrimaryKey($tableName),
//                    "$pixieCode / $tableName: " . $pkName . "<>" . $kv->getPrimaryKey($tableName));
                assert($table instanceof Table, "Invalid table type");
                $rules = $config->getTableRules($tableName);
                // todo: handle rules in config!
//                $kv->map($rules, [$tableName]);
//                $kv->select($tableName);

                [$ext, $iterator, $headers] =
                    $this->setupHeader($config, $tableName, $fn);
//                assert(count($kv->getTables()), "no tables in $pixieCode");

                // takes a function that will iterate through an object
//            $kv->addFormatter(function());

                if (isset($headers)) {
                    if (count($headers) !== count(array_unique($headers))) {
//                        dd($headers, array_unique($headers));
//                        asort($headers);
//                        dd($headers);
                    }
                }
//                    assert(),
//                        "look for duplicate key!\n" .
//                        json_encode($headers, JSON_PRETTY_PRINT));
                // don't parse the header match each time, store them
//            $regexRules = $configData['tables'][$tableName]['formatter'] ?? [];
                // why not mapped headers?
                // setup the dataRules to apply later
                $dataRules = [];
                foreach ($headers as $header => $origHeader) {
                    foreach ($table->getPatches() as $headerRegex => $regexRules) {
                        if (preg_match($headerRegex, (string)$header, $mm)) {
                            $dataRules[$header] ??= [];
                            $dataRules[$header] += $regexRules;
                        }
                    }
                }
//                $iKv->beginTransaction();
//                $kv->beginTransaction(); // so that events that populate related tables are persisted.  Meh.
                // preload
                $event = $this->eventDispatcher->dispatch(new RowEvent(
                    $config->code,
                    $tableName,
                    null,
                    config: $config,
                    action: self::class,
                    type: RowEvent::PRE_LOAD,
                    context: ['count' => $counts[$fn] ?? 0]));

//                /* PROGRESSBAR!!!! */
//                $input = new ArgvInput();
//                $output = new ConsoleOutput();
//                $symfonyStyle = new SymfonyStyle($input, $output);
//                $progressBar = new ProgressBar($output, $total = $counts[$fn]??0);
//                if ($total) {
//                    $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%(estimated) %memory:6s% -- %message%');
//                } else {
//                    $progressBar->setFormat(' %current% [%bar%] %elapsed:6s% %memory:6s% -- %message%');
//                }

//                $progressBar->setFormat(OutputInterface::VERBOSITY_VERBOSE);
//                $pk = $kv->getPrimaryKey($tableName);
                $pk = $config->getTable($tableName)->getPkName();
                // this is the json/csv iterator, $rowObj comes from json/csv
                foreach ($iterator as $idx => $rowObj) {
//                    dump(reallyRaw: $rowObj);

//                    $progressBar->advance();
                    if ($startingAt && ($idx < $startingAt)) {
                        continue;
                    }
//                    $progressBar->setMessage("idx: $idx");

                    // if it's json, remap the keys.
                    // mapped Header stuff has moved to pixie:prepare
//                    if (false)
                    $rowObj = $this->extractRowObj($ext, $rowObj, $headers);
                    assert($rowObj);

                    // just check the first rowObj for a pk in rowObj
//                    $this->justCheckTheFirstRowObj($idx, $table, $tableName, $rowObj, $pk);
                    $id = $rowObj->{$pkName} ?? null;
                    assert($id, "no primary key in $tableName rowObj " . json_encode($rowObj, JSON_PRETTY_PRINT));
                    if (!$id) {
                        // e.g. empty excel rows.  Could handle in the grid:excel-to-csv
                        $this->logger->error("Empty pk, skipping rowObj " . $idx);
                        continue;
                    }
                    SurvosUtils::assertKeyExists($pkName, $rowObj, "in $fn");
                    $rowObj = $this->applyDataRules($rowObj, $dataRules);
//                    dump(afterDataRules: $rowObj);
                    if (!$rowObj) {
                        dd($rowObj, $idx, $tableName);
                        continue;
                    }

                    // _before_ row is created.  item is null.
                    //  set marking, look for license, etc.
                    // set type to RowEvent::DISCARD to _not_ insert row.

//                    dump(beforeRowEvent: $row, data: $row->getData());
//                    dd($rowEvent);

                    // move to 'process' transition, so that raw is just simple naming/casting rules
                        $rowEvent = new RowEvent(
                            $config->code,
                            $tableName,
                            item: null,
                            key: $rowObj[$pk] ?? null,
                            row: $rowObj,
                            index: $idx,
                            action: self::class,
                            context: $context);
                        $event = $this->eventDispatcher->dispatch($rowEvent);
                        $rowObj = $event->row;

                        if ($event->type !== RowEvent::DISCARD) {
                            $row = $this->addRow($rowObj, $table, $owner); // insert row from file iterator
                        } else {
                            continue; // skip anything else on this row.
                        }
                    // handling relations could be its own RowEvent too, for now it's here
//                dd($rowObj);
                    //

                        // since we already have a row id, we can create the related images from the raw data.
                    // $this->importService (injected!) ->processImages()
                    /** @var OriginalImageModel $original */
                    foreach ($rowObj['originalImages']??[] as $original) {
                        // for use when sais isn't populated
                        $rowObj['originalUrl'] = $original->imageUrl;
                        $thumb = $original->context['thumb']??null;
                        $rowObj['thumburl'] = $thumb;
                        $imageEntity =  $this->addImage($row, $original, $thumb );
                        $saisImages[] = $imageEntity;
                    }
//                    $row['saisImages'][] = $item->addImage($imageEntity);

                    $rowObj = $this->handleRelations($ctx, $row, $config, $pixieCode, $table, $owner, $rowObj);

//                    $rowObj = $this->handleImages($kv, $row, $config, $pixieCode, $table, $owner, $rowObj);

//                    dd(afterRelations: $rowObj);
                    $event->row = $rowObj; // is this needed?
//                $tableName=='obj' && dd($rowObj['classification'], $event->rowObj['classification']);

//                $tableName == 'obj' && dd($rowObj);

                    if ($callback) {
                        // for batching
                        if (!$continue = $callback($rowObj, $table, $idx, $kv)) {
                            break;
                        }
                    }

//                    // seems hackish, better to use discard
//                    if (!$event->row) {
//                        dd($event);
//                        continue;
//                    }
//                    // don't set if discard
//                    if ($event->type == RowEvent::DISCARD) {
//                        // transition?  Softdelete?
//                        dd($event);
//                        continue;
//                    }

                    // add the source strings to the translation table


//                $event = new FetchTranslationObjectEvent($rowObj, )
//                    $sourceString = $rowObj[$tKey];
//                    assert($rowObj == $event->getNormalizedData());
//                    dd($rowObj, $event->getNormalizedData());
//                    dd($row, $rowObj);

                    $rowObj = $this->handleTranslations($table, $config, $rowObj, $row, $pixieCode, $tableName);
                    if ($limit && ($idx >= $limit - 1)) break;
                }

            }
        }

        // museum-specific handling, in Service or Workflow
        // debatable, should rename the events if we're going to keep them.
        if (0)
        $event = $this->eventDispatcher->dispatch(new RowEvent(
            $config->code, $tableName,
            null,
            action: self::class,
            type: RowEvent::POST_LOAD,
//            storageBox: $kv
        ));

        return $kv;

    }

    public function addImage(Row $row, OriginalImageModel $original, ?string $thumbUrl=null): OriginalImage
    {
        /** @var OriginalImage */
        if (!$image = $this->repo($this->entityManager, OriginalImage::class)->find($original->getKey())) {
            // create the entity
            $image = new OriginalImage($original->imageUrl, $original->getKey(), $original->root);
            $row->addImage($image);
            $this->entityManager->persist($image);
        }
        if ($thumbUrl) {
            $image->setThumbUrl($thumbUrl);
        }
        return $image;
    }

    public function createKv(array   $fileMap,
                             Config  $config,
                             string  $pixieCode,
                             ?string $subCode,
                             bool    $destroyFirst = false
    ): StorageBox
    {
//        assert(($pixieCode<>'md') || $subCode, "$pixieCode $subCode");
        // only create the tables that match the filenames
        $tablesToCreate = [];
        foreach ($fileMap as $fn => $tableName) {
            $tables = $config->getTables();
            foreach ($tables as $tableName => $tableData) {
                $tablesToCreate[$tableName] = $tableData;
            }
        }
        $pixieFilename = $this->pixieService->getPixieFilename($pixieCode, $subCode);
        if ($destroyFirst) {
            $this->pixieService->destroy($pixieFilename);
        }

        $kv = $this->pixieService->getStorageBox($pixieCode, subCode: $subCode,
            createFromConfig: true,
        );
//        if (str_contains($kv->getFilename(), 'edu')) dd($kv->getFilename());
        return $kv;
    }

    /**
     * @param mixed $splFile
     * @param array $configData
     * @param mixed $tableName
     * @param StorageBox $kv
     * @param int|string $fn
     * @return array
     * @throws \JsonMachine\Exception\InvalidArgumentException
     * @throws \JsonMachine\Exception\PathNotFoundException
     * @throws \League\Csv\Exception
     * @throws \League\Csv\InvalidArgument
     * @throws \League\Csv\SyntaxError
     * @throws \League\Csv\UnavailableStream
     */
    public function setupHeader(Config $config, string $tableName, int|string $fn): array
    {
        $ext = pathinfo($fn, PATHINFO_EXTENSION);
        if ($ext === 'json') {
            $iterator = Items::fromFile($fn)->getIterator();
            $firstRow = $iterator->current();
            // @todo: handle nested properties
            $headers = array_keys(get_object_vars($firstRow));
            $iterator->rewind();
        } else { // if (in_array($ext, ['tsv', 'csv', 'txt'])) {
            $csvReader = Reader::createFromPath($fn, 'r');
            $result = Info::getDelimiterStats($csvReader, ["\t", ','], 3);
            // pick the highest one
            arsort($result);
            $csvReader->setDelimiter(array_key_first($result));
            $csvReader->setHeaderOffset(0); //set the CSV header offset

            $headers = $csvReader->getHeader();
//                assert(array_key_exists($tableName, $configData), json_encode($configData));
//                dd($originalHeaders, $headers);
        }

        $rules = $config->getTableRules($tableName);
        $table = $config->getTable($tableName);
        $mappedHeader = $headers;
        // this has been moved to the DTO's!
//        $mappedHeader = $kv->mapHeader($headers,
//            $config->getSource()->propertyCodeRule,
//            regexRules: $rules);
//        ($tableName == 'obj') && dd($tableName, $mappedHeader);
        // keep for replacing the key names later
//                dd($headers, mapped: $mappedHeader, rules: $rules);
        $this->eventDispatcher->dispatch(
            $headerEvent = new CsvHeaderEvent($mappedHeader, $fn)
        );
        $headers = $headerEvent->header;
//
        // headers is now a map from column headers to properties
        if (count($headers) != count(array_unique($headers))) {
            dd($headers, array_unique($headers));
        }

        if ($ext !== 'json') {
            try {
                $headerKeys = array_keys($headers);
                $iterator = $csvReader->getRecords($headerKeys);
            } catch (SyntaxError $error) {
                dd($headers, $error->getMessage());
            }
        } else {
            // if it's json, we might be missing headers, so add them if it's in the table
            foreach ($table->getProperties() as $property) {
                if (!array_key_exists($propertyCode = $property->getCode(), $headers)) {
                    $headers[$propertyCode] = $propertyCode;
                }
            }
        }
//        dd($headers, $ext);

        return [$ext, $iterator, $headers];
    }

    /**
     *
     * In many cases, the data in one table is really a reference to data in another tables, often via a label, e.g.
     *     material: bronze, color: [red, blue], collection: ROM, lang: de, loc:001-02
     *
     * Sometimes a related list exists in the database, like collections or even people.  If not, we need to populate the related list with the label.
     *
     *
     *
     * @param string $pixieCode
     * @param Table $table
     * @return array $row modified row, side effect of creating lists.
     */
    public function handleRelations(PixieContext $ctx,
                                    Row         $item,
                                    Config      $config,
                                    string      $pixieCode,
                                    Table       $table,
                                    Owner       $owner,
                                    array       $row): array
    {
        $strRepo = $ctx->repo(Str::class);
        foreach ($table->getProperties() as $property) {

            $propertyCode = $property->getCode();
            $settings = $property->getSettings();

            // only lists and relations
            if (!$relatedTableName = $property->getSubType()) {
                continue;
            }

            if (!$label = $row[$propertyCode] ?? null) {
                continue; // skip if blank
            }

            // list and relation are merging...
            if ($property->isRelation()) {
                $relatedTable = $config->getTable($relatedTableName);
                if ($delim = $property->getDelim()) {
                    $values = array_map('trim', explode($delim, (string)$row[$propertyCode]));
                    if ($property->getValueType() == '@pk') {
                        // @todo: make a many-to-many table.  For now, a simple array
                        $row[$propertyCode] = $values;
                    } elseif ($property->getValueType() == '@label') {
                        // create a new table.
                        dd($property, $settings, $row[$property->getCode()], $values);
                    }
                }
            }

            if ($relatedTableName = $property->getListTableName()) {
                $relatedTable = $config->getTable($relatedTableName);
                assert($relatedTable, "Missing related table $relatedTableName");
                $pkName = $relatedTable->getPkName();
                $valueType = $property->getValueType();
//                ($propertyCode == 'classification') && dd($property, $relatedTable, $relatedTableName, $this->listsByLabel);
                // first time, cache
                if (!array_key_exists($relatedTableName, $this->listsByLabel)) {
                    $this->listsByLabel[$relatedTableName] = [];
                    // eh, don't we have this as a pixie table?

                    // use core instances!  This seems bad, could be too many row
                    // should be on-demand
                    foreach ($this->repo($this->entityManager, Row::class)->findAll() as $rowEntity) {
                        $this->listsByLabel[$rowEntity->getCoreCode()][$rowEntity->getLabel()] = $rowEntity->getId();
                    }
//                    dd($this->listsByLabel);
//                    foreach ($kv->iterate($relatedTableName) as $relatedRowData) {
//                        $this->listsByLabel[$relatedTableName][$relatedRowData->label()] =
//                            $relatedRowData->getKey();
//                    }
                }
                $isMultiple = is_array($label); // @todo: define in @list or @rel
                if ($isMultiple) {
                    $labels = $label;
                    $row[$propertyCode] = []; // reset the existing text labels
                } else {
                    $labels = [$label];
                }
                foreach ($labels as $label) {
                    if (!$label) {
                        continue;
                    }
                    // if label is missing, create it in the relatedTable pixie
                    assert(is_string($relatedTableName), json_encode($relatedTableName));
//                    $relatedTableName=='tag' && dd($label, $labels, $this->listsByLabel[$relatedTableName]);

                    assert(is_string($label), "$relatedTableName " . json_encode($label));

                    SurvosUtils::assertKeyExists($relatedTableName, $this->listsByLabel);
//                    SurvosUtils::assertKeyExists($label, $this->listsByLabel[$relatedTableName], "missing label in listsByLabel $relatedTableName");
                    if (!array_key_exists($label, $this->listsByLabel[$relatedTableName])) {
                        if ($valueType === '@code') {
                            //
                        } elseif (in_array($valueType, ['@label', '@labels'])) {
//                                dd($relatedRowData);
                            $owner = $item->getCore()->owner;
                            if (!$sourceLang = $owner->locale ?? null) {
                                if (!$sourceLang = $config->getSource()->locale) {
                                    assert(false, "unable to get source language");
                                    dd($row);
                                }
                            }
                            $relatedId = TranslationClientService::calcHash($label, $sourceLang);
                            $relatedRowData = [
                                'label' => trim($label),
//                                    '_locale' => $sourceLang, // should every row have a locale?
                            ];
                            $_t[$sourceLang][PixieInterface::TRANSLATION_LABEL] = $relatedRowData['label'];

//                            ($relatedTableName == 'loc') && dd($relatedRowData, $relatedTable);

                            if (count($relatedTable->getTranslatable())) {


                                // this is to get the orig strings.  No database lookup
//                            dump($relatedRowData);
                                // it seems like a lot of work to get the source hash, but it also modifies the $row and adds hash.  I think.
                                // candidate for review
                                if (class_exists(FetchTranslationObjectEvent::class)) {
                                    $event = $this->eventDispatcher->dispatch(
                                        new FetchTranslationObjectEvent($relatedRowData,
                                            $sourceLang,
                                            $sourceLang,
                                            row: $item,
                                            ctx: $ctx,
                                            pixieCode: $pixieCode,
                                            table: $relatedTableName,
                                            key: $relatedId,
                                            keys: [PixieInterface::TRANSLATION_LABEL]
                                        )
                                    );

                                    // add to the pixie string table to translate later.  Don't overwrite
                                    /** @var Translation $translationModel */
                                    foreach ($event->translationModels as $translationModel) {
                                        assert($translationModel->isSource(), "translations have been moved.");
//                                        dd($translationModel->toArray(), $translationModel->getHash());
                                        // same as in handleRelations, need to refactor.
                                        $hash = $translationModel->getHash();
                                        if (!$str = $strRepo->find($hash)) {
                                            $str = new Str(
                                                code: $translationModel->getHash(),
                                                original: $translationModel->getText(),
                                                srcLocale: $translationModel->getSource(),
                                            );
                                            $this->entityManager->persist($str);
                                        }
                                    }

                                    // the label and _translations have been set
                                    $relatedRowData = $event->getNormalizedData();
//                                    dd($relatedRowData);
                                    // set the related row field to the hash, not the text
                                    $relatedId = $relatedRowData[PixieInterface::TRANSLATION_LABEL]; //
//                                    dd($relatedId, $relatedRowData, $relatedTableName, $_t);
                                    // replace the key with the translation key
                                    $relatedRowData[$pkName] = $relatedId;
//                                dd(related: $relatedRowData);
                                }
                                // populate the related table
                                $relatedRowData[PixieInterface::TRANSLATED_STRINGS] = $_t;

                                $relatedRow = $this->addRow($relatedRowData, $relatedTable, $owner); // related
//                                dd($relatedRowData, $relatedTable, $relatedId, $relatedRow);


//                                $kv->set($relatedRowData, $relatedTableName);
//                                $kv->set($relatedRowData, $relatedTableName, properties: [
//                                    '_t' => $_t,
//                                ]);
//                                $kv->commit();
//                                $newItem = $kv->get($relatedId, $relatedTableName);
////                                dd($pkName, $relatedRowData, $newItem, $newItem->getData());
//                                $kv->beginTransaction();
                                $this->listsByLabel[$relatedTableName][$label] = $relatedId;
                            } else {
                                // we can have untranslated labels, like loc codes
                                $this->listsByLabel[$relatedTableName][$label] = $label;
//
//                                assert(false, "valueType not handled " . $propertyCode . ' ' . $valueType);
                            }
                        }
                    }

//                    AppService::assertKeyExists($propertyCode, $row, "missing in table " . $table->getName());

                    $relatedId = $this->listsByLabel[$relatedTableName][$label];
//                    dd($item->getRaw(), $item->getData(), $row, $relatedRow, $relatedTableName, $relatedRowData);
                    if ($isMultiple) {
//                        dd($row, $propertyCode);
                        $row[$propertyCode][] = $relatedId; // if this is a string, this could instead be the translation source key?
//                        dd($row[$propertyCode]);
                    } else {
                        $row[$propertyCode] = $relatedId; // if this is a string, this could instead be the translation source key?
                    }
                }

                // fetch the key by label?  Or keep it in memory?
                // create the relation.  unlike the old system, we can have relations in the sql
            }

        }
        $item->setData($row); // the processed data?
        return $row;
    }

    /**
     * @param mixed $row
     * @param array $dataRules
     * @param array $mm
     * @return array
     */
    public function applyDataRules(mixed $row, array $dataRules): array
    {
        // eh, this isn't lovely.
        if (is_object($row)) {
            $row = (array)$row;
        }
        foreach ($row as $k => $v) {
            foreach ($dataRules[$k] ?? [] as $dataRegexRule => $substitution) {
                $match = preg_match($dataRegexRule, (string)$v, $mm);
                if ($match) {
                    if ($substitution === '') {
                        $row[$k] = null;
                    } else {
                        // or a preg_replace?
                        $row[$k] = str_replace($mm[0], $substitution, $row[$k]);
//                                dd($row[$k]);
                    }
                }
            }
        }
        return $row;
    }

    public function mergeObjects(object $a, object $b): object {
        $c = (clone $a);
        foreach (get_object_vars($b) as $key => $value) {
            $c->$key = $value;
        }
        return $c;
    }

    /** @template T of object */
    public function repo(EntityManagerInterface $em, string $class)
    {
        assert($em === $this->entityManager);
        /** @var \Doctrine\Persistence\ObjectRepository<T> */
        return $em->getRepository($class);
    }


    /** the one and only place it's add to the database, to core, etc. */
    public function addRow(array|object $row, Table $table, Inst $inst): Row
    {
        $pkName = $table->getPkName();
        $tableName = $table->getName();

        $id = $row[$pkName]; // unique within core
        $core = $this->pixieService->getCore($tableName, $owner);
        $rowId = Row::RowIdentifier($core, $id);

        // @todo: inject the handler based on the configCode
        // https://symfonycasts.com/screencast/dependency-injection-attributes

        if (0) {
            if (!$instance = $this->instanceRepository->find($rowId)) {
                $instance = $core->createInstance($id);
                assert($rowId == $instance->getId(), "$rowId <> " . $instance->getId());
                $this->entityManager->persist($instance);
            }
            $this->entityManager->flush();
            dd($instance);
        }

        $rowRepository = $this->entityManager->getRepository(Row::class);

        /** @var Row $r */
        if (!$r = $rowRepository->find($rowId)) {
            $r = new Row($core, $id);
            assert($r->getId() === $rowId, "$rowId is not " . $r->getId());
            $this->entityManager->persist($r);
        }


        // hard-coded hack for testing mapper

        $targetClass = 'App\\Dto\\' . ucfirst($owner->code)
            . '\\' . ucfirst($table->getName());
        // or use the base class?
        if (class_exists($targetClass)) {
            $mapper = new ObjectMapper(propertyAccessor:
                PropertyAccess::createPropertyAccessorBuilder()->disableExceptionOnInvalidPropertyPath()->getPropertyAccessor());
            try {
                $entity = $mapper->map((object)$row, $targetClass);
//                dump($entity, $row['object_url']??null, $targetClass);
            } catch (\Exception $e) {
                dd($e);
            }
            $merged = (array)$this->mergeObjects($entity, (object)$row);
            $row = (array)$this->mergeObjects($entity, (object)$row);
            $ctx = $this->pixieService->getReference($owner->pixieCode);

//            $config = $ctx->config;
//            $table = $config->getTable($core->code);
//            $this->rowIngestor->ingest((array)$merged, $r, $owner->locale, ['label','description']);
//            dd($merged, $row, $entity, $r->getResolvedStrings(), $r->getStrCodeMap());

            $rawEntity = $this->serializer->normalize(
                $entity,
                /* format */ null,
                /* context */ [
                    // if you use #[Groups] in your DTO, you can do:
                    // 'groups' => ['product:read'],
                ]
            );

            $r->raw = $rawEntity;
        }

//        $entity->json = $row;

//        $label = $row[Instance::DB_LABEL_FIELD]??null;
//        if (!$label) {
//            // y
//            $label = 'row ' . $rowId;
//            dump($row, Instance::DB_LABEL_FIELD);
//        }
//        $r->lla($label);

//        $row = json_encode($row, JSON_FORCE_OBJECT);

        // this should be in the RowWorkflow!  here for testing only
//        assert($owner->getPixieCode(), "missing pixieCode in ".$owner->code);
        // argh, this should work and cleanup a lot!  but it doesn't
//        $this->importHandler->process($owner->code, $r);
//        dd($row, $rowId);

        // 1) Ensure we have the row’s id within core
//        $pk = $table->getPkName();
//        dd($id, $core->id);

        $idWithinCore = $id; // (string)($rowObj->{$pk} ?? $rowObj[$pk] ?? null);
        assert($idWithinCore);
//        if (!$idWithinCore) {
//            // if you assert earlier, you can skip this guard
//            continue;
//        }

// 2) Compute content hash from the source payload you consider authoritative
//    (often the mapped "rowObj" after header mapping / data rules).
//    Optionally include a version salt so changes in mapping code force reprocess.

        $hash = ImportUtil::contentHash(
            ['v' => 'pixie-import-v1', 'core' => $tableName, 'row' => $r],
            ignoreKeys: ['updated_at', '_debug', 'taskId'],   // add any ephemeral keys you want to ignore
            unicodeNormalize: true                  // if you ingest mixed Unicode sources
        );
//        $hash = null; //  hash('xxh3', json_encode($row));

// 3) Look up previous state
        /** @var RowImportState|null $state */
        if (!$state = $this->entityManager->find(RowImportState::class, [
            'core_id' => $core->id,        // Core primary key (string in your schema)
            'row_id'  => $idWithinCore,         // id within core (not the composite Row::id)
        ])) {
            $state = new RowImportState(
                $core->id,
                $id
            );
            $this->entityManager->persist($state);
        }

        $overwrite = false;
        $overwrite = true;
// 4) Skip unchanged rows unless overwrite was requested
        if ($state && !$overwrite && $state->contentHash === $hash) {
            $this->logger->warning("Skipping the write");
            // still let your progress bar advance if you need to
//            if ($callback) {
//                if (!$callback($rowObj, $table, $idx, $kv)) {
////                    break; // preserve your batching / limit semantics
//                }
//            }
        } else {
            $state->contentHash = $hash;
        }

        return $r;

    }

    /**
     * @param string $dirOrFilename
     * @return array
     */
    public function getCounts(string $dirOrFilename): array
    {
        $counts = [];
        if (file_exists($metaFileInfoFilename = $dirOrFilename . '/_files.json')) {
            $fileInfo = json_decode(file_get_contents($dirOrFilename . '/_files.json'), true);
            foreach ($fileInfo as $fInfo) {
                $ff = $fInfo['name'];
                $counts[realpath($ff)] = $fInfo['count'];
            }
        } else {
            $this->logger->warning($metaFileInfoFilename . " does not exist");
        }
        return $counts;
    }

    /**
     * @param Finder $files
     * @param Config|null $config
     * @param $mm
     * @return array
     */
    public function getFileMap(Finder $files, ?Config $config): array
    {
        $fileMap = [];
        foreach ($files as $splFile) {
            if ($splFile->isDir()) {
                // add a prefix?  Or just ignore?
                continue;
            }
//            assert($splFile->getExtension() <> 'csv', json_encode($ignore));
            $map[$splFile->getRealPath()] = u($splFile->getFilenameWithoutExtension())->snake()->toString();
            foreach ($config->getFileToTableMap() as $rule => $tableNameRule) {
                if (preg_match($rule, $splFile->getFilename(), $mm)) {
//                    dd($mm, $splFile->getFilename(), $tableName);
                    $map[$splFile->getRealPath()] = $tableNameRule;
                    break;
                }
            }
            $fileMap[$splFile->getRealPath()] = $map[$splFile->getRealPath()] ?? null;
        }
        unset($splFile);
        return $fileMap;
    }

    /**
     * @param mixed $ext
     * @param mixed $rowObj
     * @param mixed $headers
     * @param array $reverse
     * @return array
     */
    public function extractRowObj(string $ext, mixed $rowObj, mixed $headers): object
    {
        if ($ext === 'json') {
            $reverse = array_flip($headers);

            $mappedRow = [];
            // if new values after first rowObj
            $remainingHeaders = array_keys((array)$rowObj);
            foreach ($headers as $header => $headerOrig) {
                $mappedRow[$header] = $rowObj->{$headerOrig} ?? null;
            }


            // handle keys that aren't in the mapped header, which is too dependent on the first rowObj data
            $unhandledKeys = array_diff($x = array_keys((array)$rowObj), $y = array_keys($mappedRow));
            foreach ($unhandledKeys as $newKey) {
                if (!array_key_exists($newKey, $reverse)) {
//                                    assert(false, "new key $newKey missing in $tableName \n" .
//                                        json_encode($rowObj, JSON_PRETTY_PRINT) . "\n\n" .
//                                        json_encode($reverse, JSON_PRETTY_PRINT) . "
//                                    add $newKey to required fields so it exists in the first rowObj"
//                                    );
                    $mappedRow[$newKey] = $rowObj->{$newKey} ?? null;
                } else {
                }
            }
            // if there are new headers, add them
//                        dd(rowObj: $rowObj, mappedRow: $mappedRow, );
//                            $rowObj = $mappedRow;
            $rowObj = (object)$mappedRow;
        }
        return $rowObj;
    }

    /**
     * @param int|string $idx
     * @param Table $table
     * @param mixed $tableName
     * @param mixed $rowObj
     * @param string|null $pk
     * @return void
     */
    public function justCheckTheFirstRowObj(int|string $idx, Table $table, mixed $tableName, mixed $rowObj, ?string $pk): void
    {
        if ($idx == 0) {
            assert($table->getPkName(), "table $tableName has no primary key");
            assert(property_exists($rowObj, $table->getPkName()),
                $tableName . " should have primaryKey `$pk`  " . $table->getPkName()
//                            . json_encode($rowObj, JSON_PRETTY_PRINT)
            );
        }
    }

    /**
     * @param Table $table
     * @param Config|null $config
     * @param array $rowObj
     * @return array
     */
    public function handleTranslations(Table $table, ?Config $config, array $rowObj, Row $row): array
    {

        if (count($table->getTranslatable())) {

            $sourceLocale = $config->getSource()->locale ?? $rowObj['locale'] ?? null;
            if (!$sourceLocale) {
                dd($rowObj);
            }
            $tableName = $table->getName();

            // is this needed?  We have already added it above
//                        $row = $this->addRow($rowObj, $table, $owner); // tr
//                        dd($id, $tableName, raw: $rowObj, row: $row);

//                        dump(rowObj: $rowObj, transFields: $table->getTranslatable());
                if (class_exists(FetchTranslationObjectEvent::class)) {
                    // for source table, Not libre, which happens during pixie:trans --queue
                    // complicated, but this also adds the text hash to the rowObj values
                    $trEvent = $this->eventDispatcher->dispatch(
                        new FetchTranslationObjectEvent(
                            $rowObj, // or $item?
                            pixieCode: $config->getCode(),
                            sourceLanguage: $sourceLocale,
                            targetLanguage: $sourceLocale,
                            table: $table->getName(), // for debugging,
                            row: $row,
                            key: $rowObj[$table->getPkName()],
                            keys: $table->getTranslatable()
                        ));
//
//                    dd($rowObj, $trEvent->translationModels);

//                            dd($event->translationModels, $tKv->getSelectedTable());
                    // this populates the source table of the translation database,
//                            dump($rowObj);
//                            dump($rowObj);
                    /** @var Translation $transModel */
                    // this populate Str for translations.
                    foreach ($trEvent->translationModels as $transModel) {
                        /** @var $tt Str */
                        if (!$tt = $this->repo($this->entityManager, Str::class)->find($transModel->getHash())) {
                            $tt = new Str(
                                $transModel->getHash(),
                                original: $transModel->getText(),
                                srcLocale: $sourceLocale,
                            );
                            $this->entityManager->persist($tt);
                        }
                    }
                }
        }
        return $rowObj;
    }


}
