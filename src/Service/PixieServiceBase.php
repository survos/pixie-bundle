<?php

namespace Survos\PixieBundle\Service;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\PixieBundle\CsvSchema\Parser;
use Survos\PixieBundle\Debug\TraceableStorageBox;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Meta\PixieInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Model\PixieContext;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Model\Source;
use Survos\PixieBundle\Model\Table;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Yaml\Yaml;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\PixieBundle\Entity\CoreDefinition;
use Survos\PixieBundle\Entity\FieldDefinition;


class PixieServiceBase
{
    const PIXIE_TRANSLATION = 'translation';
    // cache, indexed by filename
    private array $storageBoxes = [];

    public function __construct(
//        #[Autowire('%kernel.debug%')] private readonly bool                                        $isDebug,

        protected EntityManagerInterface              $pixieEntityManager,
        protected readonly PropertyInfoExtractorInterface $propertyInfo,
        protected readonly bool                       $isDebug = false,
        protected array                               $data = [],
        protected readonly string                     $extension = "db",
        protected readonly string                     $dbDir = 'pixie',
        protected readonly string                     $dataRoot = 'data', //
        protected readonly string                     $configDir = 'config/packages/pixie',
        protected array                               $bundleConfig = [],
        #[Autowire('%kernel.project_dir%')]
        protected readonly ?string                    $projectDir = null,
        protected readonly ?LoggerInterface           $logger = null,
        protected readonly ?Stopwatch                 $stopwatch = null,
        protected readonly ?PropertyAccessorInterface $accessor = null,
        protected readonly ?SerializerInterface       $serializer = null,
        protected readonly ?WorkflowHelperService     $workflowHelperService = null,
        protected ?Config                             $config = null,
        public ?string                              $currentPixieCode = null, // hackish
//        private ?DenormalizerInterface                      $denormalizer = null,
    )
    {
//        dd($this->isDebug);
//        dd($this->serializer->denormalize($this->data, Config::class));
//        assert($this->logger);
//        $this->denormalizer = $this->serializer; // ->denormalize($this->data, DenormalizerInterface::class);
    }


    /**
     * if null, use the value in survos_pixie.yaml, so dev can be less
     *
     * @param int|null $limit
     * @return int|null
     */
    public function getLimit(?int $limit = null): ?int
    {
        return is_null($limit) ? $this->bundleConfig['limit'] : $limit;

    }

    public function getPixieEntityManager(): EntityManagerInterface
    {
        return $this->pixieEntityManager;

    }

    public function getPixieFilename(string $pixieCode, ?string $filename = null, bool $autoCreateDir = false): string
    {
        if (!$filename) {
            $filename = $pixieCode;
        }
        $dir = $this->getPixieDbDir(); // no longer nested . "/$pixieCode";
        if ($autoCreateDir && !is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $filename = $dir . "/$filename.{$this->extension}";

        if (file_exists($filename)) {
            $filename = realpath($filename);
        }
        return $filename;

    }

    public static function getMeiliIndexName(string $pixieCode, ?string $subCode, ?string $tableName)
    {
        assert(false);
        return 'px_' . $pixieCode . ($subCode ? ('_' . $subCode) : '') . '_' . $tableName;

    }

    protected function resolveFilename($filename, ?string $type = null): ?string
    {

        if ($type && ($filename && !file_exists($filename))) {
            $root = match ($type) {
                'db' => $this->dbDir,
                'config' => $this->configDir,
                'data' => $this->dataRoot,
            };
            $filename = $root . "/$filename";
            if (!file_exists($filename)) {
                $filename = $this->projectDir . "/$filename";
            }
        }
        return ($filename && file_exists($filename)) ? $filename : null;
    }

    // fetch via an event, rather than injecting the service
//    #[AsEventListener(event: StorageBoxEvent::class, priority: 50)]
    public function storageBoxListener(StorageBoxEvent $event): void
    {
        return;
        $mode = $event->getMode();
//        if ($event->isTranslation()) {
//            $mode = PixieInterface::PIXIE_TRANSLATION;
//        }
//        assert(!$event->isTranslation(), "use mode");
        $filename = $this->getPixieFilename($event->getPixieCode());
        if ($suffix = match ($mode) {
            'data' => null,
//            'translation' => PixieInterface::PIXIE_TRANSLATION_SUFFIX,
            'image' => PixieInterface::PIXIE_IMAGE_SUFFIX
        }) {
            $filename = str_replace('.pixie.db', '-' . $suffix . '.pixie.db', $filename);
        }

        $kv = $this->getStorageBox(
            $suffix ?? $event->getPixieCode(),
            filename: $suffix ? $filename : null,
        );
//        dd($kv);
        $event->setStorageBox($kv);
    }

    function getStorageBox(string  $pixieCode,
                           ?string $subCode = null,
                           ?string $filename = null, // since files can share a config?
                           bool    $destroy = false,
                           bool    $createFromConfig = false,
                           ?Config $config = null,
    ): PixieContext
    {
        return $this->getReference($pixieCode);
        // ideally we could drop this and get the configuration data without the file
        // this selects the proper database so when others access the em it is the right one
        assert(!str_contains($pixieCode, "/"), "pass in pixieCode, not filename");
//        assert($filename, $pixieCode . " $filename ");

        if (!$filename) {
            $filename = $this->getPixieFilename($pixieCode, $subCode);
        }
        if ($createFromConfig && !$config) {
            $config = $this->selectConfig($pixieCode);
        }
        // always parse config so we have it.  certainly could be optimized
//        if ($createFromConfig)
        {
            if (!$config) {
//                assert(false, "Pass in config for now.");
                // filename? Or code???  ugh,
                $config = $this->selectConfig($pixieCode);

            }
            // the array! someday the model.
//            $tables = $config->getTables();
//            foreach ($config->getTables() as $tableName => $table) {
//                dd($tableName, $table);
//            }
        }
        $destroy && $this->destroy($filename);
        if (!$kv = $this->storageBoxes[$filename] ?? false) {
            $class = $this->isDebug ? TraceableStorageBox::class : StorageBox::class;
            $kv = new $class($filename,
                $this->data, // for debug
                $config,
                pixieCode: $pixieCode,
                accessor: $this->accessor,
                logger: $this->logger,
                stopwatch: $this->stopwatch,
                templates: $this->getTemplates()
            );
            $this->storageBoxes[$filename] = $kv;
        }
//        dump($filename, storageBoxes: array_keys($this->storageBoxes));
        return $kv;
    }

//    function getStringBox(string $filename, array $tables=[]): StorageBox
//    {
//        return new StorageBox($filename, $tables, valueType: 'string', logger: $this->logger);
//    }

    function destroy(string $filename): void
    {
        $filename = $this->resolveFilename($filename);
        if ($filename && file_exists($filename)) {
            unlink($filename);
        }
    }

    /** @internal used in the DataCollector class */
    public function getData(): array
    {
        return $this->data;
        foreach ($this->storageBoxes as $filename => $storageBox) {
            $this->data[$filename] = $storageBox->getData();
        }
        return $this->data;
    }

    public function getConfig(string $pixieCode): Config
    {
        return $this->getConfigFiles()[$pixieCode];

    }

    /**
     * @return array<Config>
     */
    public function getConfigFiles(?string $q = null, ?string $pixieCode = null, int $limit = 0): array
    {

        $configs = [];
        foreach ($this->bundleConfig['pixies'] as $code => $pixie) {
            if ($q && !str_contains((string)$code, $q)) {
                continue;
            }
            if ($pixieCode && $code !== $pixieCode) {
                continue;
            }

//            https://www.strangebuzz.com/en/snippets/converting-an-array-into-an-object-with-the-symfony-serializer
//            $code=='auur' && dd($pixie['tables']['obj']);
            // insert the name so we don't have to fix it manually later
            foreach ($pixie['tables'] as $tableName => $tableData) {
                $pixie['tables'][$tableName]['name'] = $tableName;
            }

//            $type = $this->propertyInfo->getType(Config::class, 'tables');

            $config = $this->serializer->denormalize($pixie, Config::class);
            $config->pixieFilename =  $this->getPixieFilename($code);
            $config->code = $code;


            // eh.
            $resolvedDataPath = $this->resolveFilename($config->dataDir, 'data');
            $config->dataDir = $resolvedDataPath;

            $configs[$code] = $config;
        }
        return $configs;

        $finder = new Finder();
        $finder->depth("<1");
        $configs = [];
        $pattern = $pixieCode ?: ($q ?: '*');
        // this is only the configs in the configDir.
        foreach ($finder->files()->name("$pattern.yaml")
                     ->in($this->getConfigDir())->sortByName()->reverseSorting() as $file) {
            // we can optimize later...
            $code = $file->getFilenameWithoutExtension();
            $config = $this->selectConfig($code);
            assert($config, "invalid config $code");

            $resolvedDataPath = $this->resolveFilename($config->getSourceFilesDir(), 'data');
            $config->dataDir = $resolvedDataPath;
//            dd($config);
            // hacky!  configs can belong to more than one filename
            $config->setPixieFilename($this->getPixieFilename($code));
            assert($config->getPixieFilename(), "Missing pixie filename for $code");
            $configs[$code] = $config;
        }
        return $configs;
//        // we could parse these, though then we should cache them.  Since they're in config, we could cache them at compile-time
//        return glob($this->getConfigDir() . '/*.yaml');

    }

    public function getTemplates(): array
    {
        $templates = [];
        foreach ($this->bundleConfig['templates'] as $code => $template) {
            $templates[$code] = $this->serializer->denormalize($template, Table::class);
        }
        return $templates;

    }

    /**
     * @return array(<string, Property>)
     */
    public function getInternalProperties(): array
    {
        $templates = [];
        foreach ($this->bundleConfig['templates']['internal'] as $code => $property) {
            dd($code, $property);
        }
        return $templates;

    }

    public function importConfigToCore(Config $config): void
    {
        $coreCode = $config->getCode();
        if (!$this->coreRepository)
            // map config to core fields, then only use Core/Fields and not Table/Columns
            dd($config);


    }

// PixieService.php
    public function getCoreInContext(PixieContext $ctx, string $tableName, bool $autoCreate = false): ?Core
    {
        $em = $ctx->em;
        $coreRepo = $ctx->repo(Core::class);
        $owner = $ctx->repo(Inst::class)->find($ctx->pixieCode);
        if (!$owner) {
            return null;
        }
        assert($owner);

        // Make sure we have a managed Owner proxy now
//        if (!$ctx->ownerRef) {
//            assert(false, "ctx must have an owner");
//            // Owner must already exist; error out if not.
//            $exists = (bool)$em->getConnection()->fetchOne(
//                'SELECT 1 FROM inst WHERE id = ?', [$ctx->pixieCode]
//            );
//            if (!$exists) {
//                throw new \RuntimeException("Owner '{$ctx->pixieCode}' not found in current pixie DB.");
//            }
//            $ctx->ownerRef = $em->getReference(Inst::class, $ctx->pixieCode);
//        }

        $core = $coreRepo->findOneBy(['code' => $tableName]);
        if ($autoCreate) {
            if (!$core) {
                $core = new Core($tableName, $tableName, $owner);
                $owner->addCore($core);
                $em->persist($core);
            } elseif ($core->owner === null) {
                $core->owner = $owner;             // repair if missing
            }
        }

        return $core;
    }

    /** Fail-fast version you can use inside services */
    public function requireContext(object|string|null $subject = null): PixieContext
    {
        $ctx = $this->contextFor($subject);
        if (!$ctx) {
            throw new \RuntimeException('PixieContext not set. Call setCurrentPixieCode() first or pass a code.');
        }
        return $ctx;
    }

    public function getCore(string $tableName, string|Inst $ownerInput): Core
    {
        $ownerCode = \is_string($ownerInput) ? $ownerInput : (string)$ownerInput->code;

        $ctx = $this->getReference($ownerCode);
        $em = $ctx->em;
        $coreRepo = $em->getRepository(Core::class);

        // Core codes are globally unique in your schema (unique index on code),
        // so scoping by owner is not required; if you later scope by owner, add it here.
        $core = $coreRepo->findOneBy(['code' => $tableName]);
        if (!$core) {
            $core = new Core($tableName, $tableName); // don’t pass Owner here
            $core->owner = $ctx->ownerRef;            // set owning side; no $owner->addCore() needed
            $em->persist($core);
        }

        return $core;
    }


    public function getCoreXX(string $tableName, string|Owner $ownerInput): Core
    {
        // 1) Resolve owner code/id (don’t trust cross-EM objects)
        $ownerCode = is_string($ownerInput) ? $ownerInput : (string)$ownerInput->code;
        $ownerId = is_string($ownerInput)
            ? $ownerInput                       // if your PK == code
            : (property_exists($ownerInput, 'id') ? (string)$ownerInput->id : (string)$ownerInput->code);

        // 2) Switch DB/EM for this owner
        $this->getConfig($ownerCode);           // sets $this->pixieEntityManager to the right EM
        $em = $this->pixieEntityManager;
        $coreRepo = $em->getRepository(Core::class);

        // 3) Get a MANAGED Owner reference in THIS EM
        $ownerRef = $em->getReference(Inst::class, $ownerId); // lightweight, no SELECT unless needed

        // (Optional) sanity: ensure the owner row exists in this DB
        // $exists = (bool)$em->getConnection()->fetchOne('SELECT 1 FROM inst WHERE id = ?', [$ownerId]);
        // if (!$exists) { throw new \RuntimeException("Owner '$ownerId' not found in current DB"); }

        // 4) Find Core by code (code is unique). Prefer also scoping by owner if code isn’t truly global.
        $core = $coreRepo->findOneBy(['code' => $tableName]);
        if (!$core) {
            // Constructor should be side-effect free; don’t pass $owner here
            $core = new Core($tableName, $tableName);
            $core->owner = $ownerRef;           // set the owning side; no addCore() needed
            $em->persist($core);
        }

        return $core;
    }

// ...


    /** If you REALLY need a managed Owner later (and only after you persist it) */
    public function attachOwnerRef(PixieContext $ctx): void
    {
        $exists = (bool)$ctx->em->getConnection()
            ->fetchOne('SELECT 1 FROM inst WHERE id = ?', [$ctx->pixieCode]);
        $ctx->ownerRef = $exists ? $ctx->em->getReference(Inst::class, $ctx->pixieCode) : null;
    }



    // @todo: add custom dataDir, etc.
// PixieService.php

// PixieService.php

    /** @deprecated Use getReference() + getConfigSnapshot() */
    private function selectConfig(string $pixieCode): ?\Survos\PixieBundle\Model\Config
    {
        assert(false);
        // 1) Load and normalize Config (from bundle config)
        $configs = $this->getConfigFiles(pixieCode: $pixieCode);
        $config = $configs[$pixieCode] ?? null;
        assert($config !== null, "Missing $pixieCode in selectConfig()");
        if (!$config) {
            return null;
        }

        // Expand templates / normalize tables & properties
        $config = \Survos\PixieBundle\StorageBox::fix($config, $this->getTemplates());

        // Ensure filenames/dirs are set
        if (!$config->getPixieFilename()) {
            $config->setPixieFilename($this->getPixieFilename($pixieCode));
        }
        if (!$config->getDataDir()) {
            $resolved = $this->resolveFilename($config->getSourceFilesDir(), 'data');
            $config->dataDir = $resolved;
        }

        // 2) SWITCH the EM/connection to this pixie DB if not already
        $em = $this->pixieEntityManager;
        $conn = $em->getConnection();

        $params = $conn->getParams();
        $currentPath = $params['path'] ?? null;                 // current sqlite path
        $targetPath = $this->dbName($pixieCode);               // desired sqlite path for this pixie

        if ($currentPath !== $targetPath) {
            // Finish any pending work on the old DB, then switch
            try {
                $em->flush();
            } catch (\Throwable $ignore) {
            }
            $em->clear();
        }

        // 3) Optionally attach Owner (if present in this pixie DB)
        try {
            $owner = $em->getRepository(\Survos\PixieBundle\Entity\Inst::class)->find($pixieCode);
            if ($owner) {
                $config->setOwner($owner);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning("selectConfig($pixieCode): owner lookup failed: " . $e->getMessage());
        }

        return $config;
    }


    public function getConfigFilename(string $pixieCode): string
    {
        // @todo: handle non-standard locations
        return $this->getConfigDir() . "/$pixieCode.yaml";
    }

    public function getConfigDir(bool $autoCreate = false): string
    {
        assert(!$autoCreate);
        $dir = $this->configDir;
        if (!file_exists($dir) && !str_starts_with($dir, "/")) {
            $dir = $this->projectDir . "/$dir";
        }

        if ($autoCreate && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;

    }

    public function getDataRoot(): string
    {
        return $this->addProjectDir($this->dataRoot);
    }

    public function getPixieDbDir(): string
    {
        $dir = $this->dbDir;

        // Resolve %env(VAR)%/suffix patterns not resolved by container
        // e.g. '%env(APP_DATA_DIR)%/pixie' → '/media/tac/.../mus/pixie'
        if (preg_match('/%env\(([^)]+)\)%(.*)/', $dir, $m)) {
            $envVal = getenv($m[1]) ?: ($_ENV[$m[1]] ?? $_SERVER[$m[1]] ?? null);
            if ($envVal !== null && $envVal !== false) {
                $dir = rtrim((string)$envVal, '/') . $m[2];
            }
        }

        if (str_starts_with($dir, '/')) {
            return $dir;
        }
        return $this->projectDir . '/' . $dir;
    }

    public function addProjectDir(string $s): string
    {
        if (!file_exists($s) && !str_starts_with($s, "/")) {
            $s = $this->projectDir . "/$s";
        }
        return $s;
    }

    public function removeProjectDir(string $s): string
    {
        return str_replace($this->projectDir . '/', '', $s);
    }


    public function getSourceFilesDir(?string $pixieCode = null,
                                      ?Config $config = null,
                                      bool    $autoCreate = false,
                                      bool    $throwErrorIfMissing = true,
                                      ?string $dir = null,
                                      ?string $subCode = null
    ): ?string
    {
//        assert(!$autoCreate);
        if (!$config) {
            if (!$config = $this->getReference($pixieCode)->config) {
                return null;
            }

        }

        if (!$dir) {
            if (!$dir = $config->getSourceFilesDir()) {
                $dir = $this->dataRoot . "/$pixieCode";
            }
        }
        if ($subCode) {
            $dir .= "/$subCode";
        }

        $dir = $this->addProjectDir($dir);
        if ($autoCreate && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if ($throwErrorIfMissing) {
            assert(file_exists($dir), "Missing source files dir $dir");
        }
        return file_exists($dir) ? realpath($dir) : null;


    }

    #[AsMessageHandler]
    public function handleTransition(PixieTransitionMessage $message)
    {
        $flowName = $message->workflow;
        $transition = $message->transition;
        $kv = $this->getStorageBox($message->pixieCode);
        $row = $kv->get($message->key, $message->table);
        $workflow = $this->workflowHelperService->getWorkflow($row, $flowName);
        if (!$workflow->can($row, $transition)) {
            return; //
        }

        $tableName = $message->table;
        $key = $message->key;
        $workflow = $this->workflowHelperService->getWorkflow($row, $flowName);
        if ($workflow->can($row, $transition)) {
            $marking = $workflow->apply($row, $transition, [
                'kv' => $kv
            ]);
            $context = $marking->getContext();
            $markingString = array_key_first($marking->getPlaces());
            $data = $context['data'] ?? null;
            $mode = $context['mode'] ?? StorageBox::MODE_NOOP;
            $kv->beginTransaction();
            if ($mode !== StorageBox::MODE_NOOP) {
                if ($data) {
                    $data['marking'] = $markingString;
                    //                $row->setMarking($markingString);
                    //                dd($row);
                    //                dd($marking->getPlaces());
                    $x = $kv->set($data, $tableName, key: $key, mode: $mode);
//                    $current = $kv->get($key, $tableName);
                } else {
                    dd($context, $transition, $row);
                }
            } else {
                // update marking for no-op
                $kv->set([
                    'marking' => $markingString,
                ],
                    tableName: $message->table,
                    key: $key, mode: StorageBox::MODE_PATCH);
            }
            $this->logger->info("Transition $transition  $tableName.$key to $markingString");
            $kv->commit();

            // dispatch the FIRST valid next transition
            foreach ($context['nextTransitions'] ?? [] as $transition) {
                if ($workflow->can($row, $transition)) {
                    // apply it? Or dispatch it?  or recursively call this?
                    // since it's been saved (above), we will refetch it when this is recursively called
                    $this->handleTransition(new PixieTransitionMessage(
                        $message->pixieCode,
                        $message->key,
                        $message->table,
                        $transition,
                        $message->workflow
                    ));
//                    $marking = $workflow->apply($row, $transition, [
//                        'kv' => $kv
//                    ]);
//                    dd($marking, $row, $message, $transition);
                } else {
//                    dd($row, $transition, $context);
                }
            }

        } else {
            // no biggie, we can't transition, but the message itself doesn't fail.
            $marking = $row->getMarking();
            $this->logger->info("cannot transition from $marking to $transition");
        }

    }

    public function getCountsByCore(): array
    {
        $rows = $this->pixieEntityManager->getRepository(Row::class)
            ->createQueryBuilder('r')
            ->join('r.core', 'c')
            ->groupBy('c.code')
            ->select('c.code AS coreCode, COUNT(r) AS count')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $x) {
            $out[$x['coreCode']] = (int)$x['count'];
        }
        return $out;
    }

    public function populateRecordWithRelations(Row $item, Config $config): Row
    {
        return $item;
        $table = $config->getTables()[$item->getTableName()];
        $properties = $table->getProperties();
        $data = (array)$item->getData();
        foreach ($properties as $property) {
            $propertyName = $property->getCode();
            if (!$relatedTable = $property->getSubType()) {
                continue;
            }
            {
                // get the related item from the PK stored in the item, replace it with the actual record, but without the _id?
                if (!$relatedId = $data[$propertyName] ?? null) {
                    continue;
                }
                // json from sqlite is stored as a string.
                if (is_string($relatedId) && json_validate($relatedId)) {
                    $relatedId = json_decode($relatedId);
                }
                // the subtype table needs to exist!
                if ($config->getTable($property->getSubType())) {
                    // publish these properties as flickr tags, including label and description
//                    if ($property->getCode() == 'classification') {
//                        dd($relatedId, $propertyName, $relatedName, $property);
//                    }
                    if ($relatedId) {
                        // @todo: get many-to-many right, e.g. walters coll
                        if (is_array($relatedId)) {
                            $data[$propertyName] = [];
                            foreach ($relatedId as $code) {
                                // during dev, relations may not be loaded, walters has 4000 creators
                                if ($relatedItem = $kv->get($code, $property->getSubType())) {
//                                    dd($data, $propertyName, $relatedItem);
                                    $data[$propertyName][] = $relatedItem;
//                                        $data[$propertyName][] = $relatedItem->label();
                                } // subtype is related table
                            }
                        } else {
                            assert(!is_iterable($relatedId), json_encode($relatedId));
                            $relatedItem = $kv->get($relatedId, $property->getSubType()); // subtype is related table
                            $relatedName = str_replace('_id', '', $propertyName);
                            // @todo: list or 1-many
                            $data[$relatedName] = $relatedItem;
//                            if ($propertyName == 'classification') dd($data, $relatedName);
                        }
                    }
                }
            }
        }
        // need to be selective about when we save this.
        $item->setData($data);
        return $item;
    }

    public function dbName(string $code, bool $throwErrorIfMissing = false): string
    {
        // Use getPixieFilename() which correctly resolves APP_DATA_DIR/pixie/<code>.db
        $dbName = $this->getPixieFilename($code);
        if ($throwErrorIfMissing) {
            assert(file_exists($dbName), $dbName);
        }
        return $dbName;
    }


    /**
     * Switch the shared pixie EM connection to the DB file for $pixieCode,
     * WITHOUT querying any tables. Safe to call before schema exists.
     */
    public function switchToPixieDatabase(string $pixieCode): EntityManagerInterface
    {
        $em = $this->pixieEntityManager;
        $conn = $em->getConnection();

        $targetPath = $this->dbName($pixieCode);           // desired sqlite path
        $currentPath = $conn->getParams()['path'] ?? null;

        if ($currentPath !== $targetPath) {
            try {
                $em->flush();
            } catch (\Throwable $ignore) {
            }
            $em->clear();
            $conn->selectDatabase($targetPath);             // will create an empty file if missing
        }

        // Make current pixie visible to listeners using this service
        $this->currentPixieCode = $pixieCode;

        return $em;
    }

    /**
     * Ensure the Pixie schema (Owner/Core/Row/etc.) exists in the given EM.
     * Idempotent: if tables already exist, this is a no-op.
     */
    public function ensureSchema(EntityManagerInterface $em): void
    {
        $sm = $em->getConnection()->createSchemaManager();
        // Bootstrap check on a canonical table
        if ($sm->tablesExist(['inst'])) {
            return;
        }

        $tool = new SchemaTool($em);
        $classes = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            $name = $meta->getName();
            if (str_starts_with($name, 'Survos\\PixieBundle\\Entity\\')) {
                $classes[] = $meta;
            }
        }

        // Create/align schema for Pixie entities
        if ($classes) {
            // 'saveMode' = true keeps existing tables, creates missing parts
            $tool->updateSchema($classes, true);
        }
    }

    private function createOrReplaceViews(Config $config, EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        // @todo: get this from the metadata or the core entities

        $config = StorageBox::fix($config); // expand templates
        $actualFields = ['label', 'code', 'id'];

        $viewSql = [];
        foreach ($config->getTables() as $table) {
            $fieldNames = array_map(
                fn(Property $p) => $p->getCode(),
                iterator_to_array($table->getProperties())
            );

            $columns = array_map(
                fn(Property $p) => in_array($p->getCode(), $actualFields, true)
                    ? "row." . $p->getCode()
                    : sprintf("json_extract(data, '$.%s') AS %s", $p->getCode(), $p->getCode()),
                iterator_to_array($table->getProperties())
            );

            $viewName = 'v_' . $table->getName();
            $viewSql[] = "DROP VIEW IF EXISTS $viewName";
            $viewSql[] = sprintf(
                "CREATE VIEW %s (%s) AS
                 SELECT %s
                 FROM row WHERE core_id = '%s'",
                $viewName,
                implode(', ', $fieldNames),
                implode(', ', $columns),
                $table->getName()
            );
        }

        foreach ($viewSql as $sql) {
            try {
                $conn->executeQuery($sql);
            } catch (\Throwable $e) {
                // keep going; views are best-effort
            }
        }
    }

    /**
     * Resolve a PixieContext from an entity or a code.
     * - Row: uses $row->getCoreCode()
     * - string: treated as config/pixie code
     * - otherwise: falls back to $this->currentPixieCode
     */
    public function contextFor(object|string|null $subject = null): ?PixieContext
    {
        // 1) explicit code given
        if (\is_string($subject) && $subject !== '') {
            return $this->getReference($subject);
        }

        // 2) entity-based inference (extend with other entity types if desired)
        if ($subject instanceof Row) {
            $code = $subject->getCoreCode();     // your Row already exposes this
            if ($code) {
                return $this->getReference($code);
            }
        }

        // 3) fallback to the service-wide "current" code
        if ($this->currentPixieCode) {
            return $this->getReference($this->currentPixieCode);
        }

        // Could also throw here if you prefer hard failure:
        // throw new \RuntimeException('Unable to resolve PixieContext; set currentPixieCode or pass a code.');
        return null;
    }

    /**
     * Set the current pixie code explicitly (CLI/controllers can call this).
     */
    public function setCurrentPixieCode(?string $code): void
    {
        $this->currentPixieCode = $code ?: null;
    }

    public function getReference(?string $pixieCode=null): PixieContext
    {
        if (!$pixieCode) {
            $pixieCode = $this->currentPixieCode;
        }

        // Always switch to the pixie database to ensure we have the right EM
        $em = $this->switchToPixieDatabase($pixieCode);
        $this->ensureSchema($em);

        $config = $this->buildConfigSnapshot($pixieCode, $em);

        $ownerRef = null;
        if ((bool)$em->getConnection()->fetchOne('SELECT 1 FROM inst WHERE id = ?', [$pixieCode])) {
            $ownerRef = $em->getReference(Inst::class, $pixieCode);
        }

        $this->currentPixieCode = $pixieCode;
        return new PixieContext($pixieCode, $config, $em, $ownerRef);
    }

    public function OLDgetReference(?string $pixieCode = null): PixieContext
    {
        if (!$pixieCode) {
            $pixieCode = $this->currentPixieCode;
        } else {
            $em = $this->switchToPixieDatabase($pixieCode); // switch sqlite file
            $this->ensureSchema($em);                       // ensure ORM tables exist
        }

        $config = $this->buildConfigSnapshot($pixieCode, $em); // <- from current EM

        $ownerRef = null;
        if ((bool)$em->getConnection()->fetchOne('SELECT 1 FROM inst WHERE id = ?', [$pixieCode])) {
            $ownerRef = $em->getReference(Inst::class, $pixieCode);
        }

        $this->currentPixieCode = $pixieCode;
        return new PixieContext($pixieCode, $config, $em, $ownerRef);
    }



//    public function getReference(string $pixieCode): PixieContext
//    {
//        // 1) Switch the EM to this pixie DB (creates empty file if missing)
//        $em = $this->switchToPixieDatabase($pixieCode);
//
//        // 2) Ensure the ORM schema for Pixie entities exists (idempotent)
//        $this->ensureSchema($em);
//
//        // 3) Pure config snapshot (never attach a managed entity to Config)
//        $config = $this->getConfigSnapshot($pixieCode);
//
//        // 4) Owner proxy (if the row exists)
//        $ownerRef = null;
//        if ((bool)$em->getConnection()->fetchOne('SELECT 1 FROM inst WHERE id = ?', [$pixieCode])) {
//            $ownerRef = $em->getReference(Inst::class, $pixieCode);
//        }
//
//        // 5) Remember for contextFor()/setCurrentPixieCode()
//        $this->currentPixieCode = $pixieCode;
//
//        return new PixieContext($pixieCode, $config, $em, $ownerRef);
//    }

    /**
     * Build a pure, immutable Config snapshot for the current pixie,
     * using compiled schema (CoreDefinition/FieldDefinition) from the given EM.
     */
    /**
     * Build a pure, immutable Config snapshot for the current pixie,
     * using compiled schema (CoreDefinition/FieldDefinition) from the given EM.
     * If no compiled schema exists yet, fall back to the YAML tables unchanged.
     */
    private function buildConfigSnapshot(string $pixieCode, EntityManagerInterface $em): Config
    {
        // base copy from bundle YAML (labels, paths etc.)
        $yaml = $this->getConfigFiles(pixieCode: $pixieCode)[$pixieCode]
            ?? throw new \RuntimeException("Unknown pixie '$pixieCode'");

        $cfg = clone $yaml;
        $cfg->setOwner(null); // NEVER attach managed entities to Config
        $cfg->setPixieFilename($this->getPixieFilename($pixieCode));
        $cfg->dataDir = $this->resolveFilename($cfg->getSourceFilesDir(), 'data');

        // read compiled schema (if any)
        $coreDefs = $em->getRepository(CoreDefinition::class)
            ->findBy(['ownerCode' => $pixieCode], ['core' => 'ASC']);

        // If there is no compiled schema, keep YAML-provided tables intact
        if (!$coreDefs) {
            return $cfg; // << fallback
        }

        // Rebuild properties from compiled schema
        $tables = [];
        foreach ($coreDefs as $def) {
            $tName = $def->core;
            $pk = $def->pk;

            $fds = $em->getRepository(FieldDefinition::class)
                ->findBy(['ownerCode' => $pixieCode, 'core' => $tName], ['position' => 'ASC', 'id' => 'ASC']);

            $props = [];
            foreach ($fds as $fd) {
                $p = new Property($fd->code);
                // Optionally derive flags from kind/target/delim:
                // $p->setSubType($fd->getTargetCore());
                $props[] = $p;
            }

            // keep the YAML table object but replace pk/properties
            $t = $cfg->getTable($tName);
            if ($t) {
                $t->setPkName($pk);
                $t->setProperties($props);
                $tables[$tName] = $t;
            }
        }

        // Only override tables if we actually reconstructed at least one
        if ($tables) {
            $cfg->setTables($tables);
        }

        return $cfg;
    }

    public function migrateDatabase(Config $config, EntityManagerInterface $em): void
    {
        $code = $config->getCode();
        $this->logger?->info("Starting migration for pixie: {$code}");

        $toDbPath = $this->absolutePath($this->dbName($code, false));
        $this->logger?->debug("Target database path: {$toDbPath}");

        $targetConn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path'   => $toDbPath,
        ]);
        $platform = $targetConn->getDatabasePlatform();
        $sm = $targetConn->createSchemaManager();

        // CURRENT (what exists now)
        $current = $sm->introspectSchema();
        $currentTableCount = count($current->getTables());
        $this->logger?->info("Current schema has {$currentTableCount} tables");

        // DESIRED (from ORM metadata)
        $metadataFactory = $em->getMetadataFactory();

        // Try getAllMetadata first, but it often returns empty after switching databases
        $allMeta = []; //  $metadataFactory->getAllMetadata();
        $this->logger?->debug("getAllMetadata() returned " . count($allMeta) . " metadata objects");

        // If empty, manually load metadata for PixieBundle entities
        if (empty($allMeta)) {
            $this->logger?->info("getAllMetadata() returned empty, scanning for PixieBundle entities");
            $entityClasses = $this->getPixieEntityClasses();

            foreach ($entityClasses as $entityClass) {
                try {
                    $metadata = $metadataFactory->getMetadataFor($entityClass);
                    $allMeta[] = $metadata;
                    $this->logger?->debug("Loaded metadata for: {$entityClass}");
                } catch (\Exception $e) {
                    $this->logger?->warning("Skipping {$entityClass}: " . $e->getMessage());
                    // Continue with other entities instead of failing hard
                    continue;
                }
            }
        }

        if (empty($allMeta)) {
            throw new \RuntimeException('No Doctrine metadata found for PixieBundle entities');
        }

        $this->logger?->info("Using " . count($allMeta) . " entity metadata objects for schema generation");
        $desired = (new SchemaTool($em))->getSchemaFromMetadata($allMeta);
        $desiredTableCount = count($desired->getTables());
        $this->logger?->info("Desired schema has {$desiredTableCount} tables");

        // Generate SQL for schema changes
        if ($currentTableCount === 0) {
            $this->logger?->info("Target database is empty, creating full schema");
            $sql = $desired->toSql($platform);
        } else {
            $this->logger?->info("Comparing schemas and generating diff");
            $comparator = $sm->createComparator();
            $diff = $comparator->compareSchemas($current, $desired);

            if ($diff->isEmpty()) {
                $this->logger?->info("No schema changes needed");
                return;
            }

            $sql = $platform->getAlterSchemaSQL($diff);
        }

        $sqlCount = count($sql);
        $this->logger?->info("Executing {$sqlCount} SQL statements");

        // SQLite: safer to disable FKs during schema changes
        $targetConn->executeStatement('PRAGMA foreign_keys = OFF;');
        try {
            foreach ($sql as $ddl) {
                $this->logger?->debug("Executing SQL: " . substr($ddl, 0, 100) . (strlen($ddl) > 100 ? '...' : ''));
                $targetConn->executeStatement($ddl);
            }
            $this->logger?->info("Schema migration completed successfully");
        } catch (\Exception $e) {
            $this->logger?->error("Schema migration failed: " . $e->getMessage());
            throw $e;
        } finally {
            $targetConn->executeStatement('PRAGMA foreign_keys = ON;');
        }
    }

    /**
     * Get PixieBundle entity classes by scanning the actual entity directory
     */
    private function getPixieEntityClasses(): array
    {
        $entityClasses = [];

        // Try multiple possible paths for the PixieBundle entities
        $possiblePaths = [
            $this->projectDir . '/vendor/survos/pixie-bundle/src/Entity',
            $this->projectDir . '/packages/pixie-bundle/src/Entity', // local development
            dirname(__DIR__) . '/Entity', // running from within bundle
        ];

        $this->logger?->debug("Scanning for PixieBundle entities in " . count($possiblePaths) . " possible paths");

        foreach ($possiblePaths as $entityDir) {
            $this->logger?->debug("Checking directory: {$entityDir}");

            if (!is_dir($entityDir)) {
                $this->logger?->debug("Directory does not exist: {$entityDir}");
                continue;
            }

            if (!is_readable($entityDir)) {
                $this->logger?->warning("Directory is not readable: {$entityDir}");
                continue;
            }

            try {
                $finder = new \Symfony\Component\Finder\Finder();
                $finder->files()->name('*.php')->in($entityDir);

                foreach ($finder as $file) {
                    $className = 'Survos\\PixieBundle\\Entity\\' . $file->getBasename('.php');

                    // Skip known non-entity files
                    $basename = $file->getBasename('.php');
                    if (str_ends_with($basename, 'Interface') ||
                        str_ends_with($basename, 'Trait') ||
                        $basename === 'CoreEntity' || // Skip CoreEntity, use Core instead
                        str_contains($basename, 'Abstract')) {
                        $this->logger?->debug("Skipping non-entity file: {$className}");
                        continue;
                    }

                    // Verify the class exists and is loadable
                    if (class_exists($className)) {
                        // Additional check: see if it has Doctrine annotations/attributes
                        try {
                            $reflection = new \ReflectionClass($className);
                            $attributes = $reflection->getAttributes(\Doctrine\ORM\Mapping\Entity::class);
                            if (!empty($attributes) || !$reflection->isAbstract()) {
                                $entityClasses[] = $className;
                                $this->logger?->debug("Found entity class: {$className}");
                            } else {
                                $this->logger?->debug("Skipping class without Entity attribute: {$className}");
                            }
                        } catch (\ReflectionException $e) {
                            $this->logger?->debug("Could not reflect class {$className}: " . $e->getMessage());
                        }
                    } else {
                        $this->logger?->debug("Class does not exist or is not loadable: {$className}");
                    }
                }

                // If we found classes in this path, stop looking
                if (!empty($entityClasses)) {
                    $this->logger?->info("Found " . count($entityClasses) . " PixieBundle entities in: {$entityDir}");
                    break;
                }
            } catch (\Exception $e) {
                $this->logger?->error("Could not scan directory {$entityDir}: " . $e->getMessage());
                continue;
            }
        }

        if (empty($entityClasses)) {
            $searchedPaths = implode(', ', $possiblePaths);
            $this->logger?->error("No PixieBundle entity classes found in any of the searched paths: {$searchedPaths}");
            throw new \RuntimeException("Could not find PixieBundle entity classes. Searched paths: {$searchedPaths}");
        }

        $this->logger?->info("Successfully discovered " . count($entityClasses) . " PixieBundle entity classes");
        return $entityClasses;
    }

    private function absolutePath(string $maybeRelative): string
    {
        if ($maybeRelative[0] === '/') {
            return $maybeRelative;
        }
        return rtrim($this->projectDir ?? \dirname(__DIR__, 2), '/') . '/' . ltrim($maybeRelative, '/');
    }


}
