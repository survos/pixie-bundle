<?php

namespace Survos\PixieBundle\Controller;

use Survos\PixieBundle\Entity\OriginalImage;
use Survos\PixieBundle\Entity\Str;
use Survos\PixieBundle\Entity\StrTranslation;
use Survos\PixieBundle\Entity\Table;
use Survos\PixieBundle\Repository\OriginalImageRepository;
use Survos\PixieBundle\Repository\RowRepository;
use Survos\PixieBundle\Repository\CoreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Meta\PixieInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Repository\StrRepository;
use Survos\PixieBundle\Service\CoreService;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieTranslationService;
use Survos\PixieBundle\Service\SchemaViewService;
use Survos\PixieBundle\StorageBox;
use Survos\PixieBundle\SurvosPixieBundle;
use Survos\StateBundle\Service\WorkflowHelperService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[Route('/legacy-pixie')]
class PixieController extends AbstractController
{
    const TRANSITION_RESET='_reset';

    public function __construct(
        private readonly ParameterBagInterface  $bag,
        private readonly PixieService           $pixieService,
        private EventDispatcherInterface        $eventDispatcher,
//        #[Target('pixieEntityManager')]
//        private EntityManagerInterface          $pixieEntityManager,

//        private PixieTranslationService         $translationService,
        private readonly RequestStack           $requestStack,
        private readonly CoreService $coreService,
        private readonly ?UrlGeneratorInterface $urlGenerator=null,
        private readonly ?MessageBusInterface   $bus=null,
        private readonly ?WorkflowHelperService $workflowHelperService = null,
        private readonly ?ChartBuilderInterface $chartBuilder = null,
    )
    {
    }

    private function getPixieConf(string $pixieCode, bool $throwIfMissing = true): ?string
    {

        $dirs = [
            $this->bag->get('config_dir'),
            $this->bag->get('kernel.project_dir') . "/config/packages/pixie/",
        ];
        $pixieCode = str_replace('.pixie', '', $pixieCode); // hack!
        foreach ($dirs as $dir) {
            $fn = $dir . "/$pixieCode.yaml";
            if (file_exists($fn)) {
                return $fn;
            }
        }
        assert(false, "$fn not found");
        return null;
    }

    #[Route('/property/{pixieCode}/{tableName}/{propertyCode}', name: 'pixie_show_property', requirements: ['key' => '.+'])]
    #[Template('@SurvosPixie/pixie/property.html.twig')]
    public function schema_property(
        string                       $pixieCode,
        string                       $tableName,
        string                       $propertyCode,
        #[MapQueryParameter] int     $limit = 50
    ): array
    {
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $kv->select($tableName);
//        $conf = $this->pixieService->getConfig($pixieCode);
//        $counts = $this->getCounts($kv, $tableName, $limit);
        // @hack, we can do better!
        foreach ($kv->getTable($tableName)->getProperties() as $property) {
            if ($propertyCode == $property->getCode()) {
                break;
            }
        }
        assert($property, $propertyCode);
        $cores = [];
        foreach ($this->coreRepository->findAll() as $core) {
            $cores[$core->code] = $core;
        }
        dd($cores);

        $chart = $this->getChartData($property, $tableName, $kv, limit:  $limit);
        return
            [
            'kv' => $kv,
            'property' => $property,
            'tableName' => $tableName,
            'pixieCode' => $pixieCode,
                'cores' => $cores,
            'chart' => $chart
        ];

    }

    #[Route('/schema/{pixieCode}', name: 'pixie_schema', requirements: ['key' => '.+'], options: ['expose' => true])]
    public function schema(string $pixieCode, Request $request, SchemaViewService $schemaView): Response
    {
        $ctx = $this->pixieService->getReference($pixieCode);
        $schemas = $schemaView->getCompiledSchema($pixieCode);
//        dd($schemas);
//        $schemas = [];
//        foreach ($ctx->repo(Core::class)->findAll() as $core) {
////            $core = $request->query->get('core'); // optional ?core=obj filter
//            $schema = $schemaView->getCompiledSchema($pixieCode, $core->code);
//            $schemas[$core->code] = $schema;
//        }
//        dd($schemas);

        // Twig namespace for PixieBundle is usually "@SurvosPixie"
        return $this->render('@SurvosPixie/pixie/schema_compiled.html.twig', $schemas);
    }

    #[Route('/share/{pixieCode}/{tableName}/{key}', name: 'pixie_share_item', requirements: ['key' => '.+'], options: ['expose' => true])]
    public function share(Request $request,
                          string                       $pixieCode,
                          string                       $tableName,
                          string                       $key,
    ): Response
    {
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $item =  $kv->get($key, $tableName);
        return $this->render('@SurvosPixie/pixie/share.html.twig', [
            'key' => $key,
            'tableName' => $tableName,
            'pixieCode' => $pixieCode,
            'row' => $item,
            'item' => $item, // redundant! use row for data!

        ]);
    }

    #[Route('/show/{pixieCode}/{tableName}/{key}', name: 'pixie_show_record', requirements: ['key' => '.+'], options: ['expose' => true])]
    #[Route('/transition/{pixieCode}/{tableName}/{key}', name: 'pixie_transition', requirements: ['key' => '.+'])]
    #[Template('@SurvosPixie/pixie/show.html.twig')]
    public function show_record(
        string                       $pixieCode,
        string                       $tableName,
        string                       $key,
        ?Request                      $request=null,
        #[MapQueryParameter] ?string $transition = null,
        #[MapQueryParameter] ?string $flowName = null,
        #[MapQueryParameter] ?string $index = null,
        #[MapQueryParameter] ?string $value = null,
        #[MapQueryParameter] int     $limit = 5
    ): array|Response
    {
        $core = null;
        $conf = $this->pixieService->getReference($pixieCode);
//        dd($this->pixieEntityManager, $tableName, $key);
        $item = $this->rowRepository->find($key);
//        $row = $this->rowRepository->findBy([
//            'key' => $key,
//            'tableName' => $tableName,
//        ]);
//        assert(false, "@todo: use pixieEntityManager instead");
//        $this->rowRepository->findBy([
//            'core' => $core,
//            'tableName' => $tableName,
//        'key' => $key,
//        ]);
//
//        $kv = $this->pixieService->getStorageBox($pixieCode);
//        $kv->select($tableName);
//        $pk = $kv->getPrimaryKey($tableName);
//        if (is_numeric($key) && ((int)$key <= 0)) {
//            $item = $kv->getByIndex((int)$key, $tableName);
//            return $this->redirectToRoute('pixie_show_record', ['pixieCode' => $pixieCode, 'tableName' => $tableName, 'key' => $item->getKey()]);
//            // get the first record, we can use this for a thumbnail too.
//        } else {
//            $item = $kv->get($key, $tableName);
//        }
//        if (!$item) {
//            throw $this->createNotFoundException("no key $key in $tableName");
//        }

        // the fields themselves should have the hash codes, the _translations has the translations
        $this->pixieService->populateRecordWithRelations($item, $conf);
        // what a pain, we need to store this somewhere else!

        $table = $conf->getTables()[$tableName];
        $workflow = $table->getWorkflow();
        assert($item::class == Row::class);
        if (!$item) {
            throw new NotFoundHttpException("No item $key in $tableName / $pixieCode");
        }
//        $keys = [];
//        foreach ($table->getTranslatable() as $field) {
//            if ($key = $item->$field()) {
//                foreach ($tKv->iterate('libre', ['hash' => $key]) as $tItem) {
//                    dump($field, $tItem->text());
//                }
//                $keys[] = $item->$field();
//            }
//        }
//        dd($keys, $item->getData(), $table->getTranslatable());
        assert($item, "no item in $pixieCode.$tableName in for $key");


        if ($request?->get('_route') == 'pixie_transition') {
            if ($transition == self::TRANSITION_RESET) {
                $kv->beginTransaction();
                $kv->set(
                    $item->getRp([
                        'marking' => null
                    ]
                ), key: $key, tableName: $tableName, mode: $kv::MODE_PATCH);
                $kv->commit();
                return $this->redirectToRoute('pixie_show_record', $item->getRp());

                dd($item);

            }
            $message = new PixieTransitionMessage($pixieCode, $key, $tableName, $transition, $flowName);
            // call it, rather than dispatch, since this is interactive, unless we pass async.
            $this->pixieService->handleTransition($message);

            return $this->redirectToRoute('pixie_show_record', $item->getRp());
        }

        // this is repeated??
//        $this->pixieService->populateRecordWithRelations($item, $conf, $kv);
//        $config = $this->pixieService->selectConfig($pixieCode);

        // quick hack for groups
        $groups = [];
        foreach ($table->getTranslatable() as $key) {
            $groups[$group='text'][] = $key;
            $reverse[$key] = $group;
        }
        foreach ($table->getProperties() as $property) {
            $settings = $property->getSettings();
            if (!$group = $settings['g']??null) {
                $index = $property->getIndex();
                if ($index) {
                    $group = $property->getIndex();
                }
            }
            $groups[$group][] = $property->getCode();
            $reverse[$property->getCode()] = $group;
        }

        foreach (array_keys($item->getData(true)) as $key) {
            if (!array_key_exists($key, $reverse)) {
                $groups['attr'][] = $key;
            }
        }
//        dd($reverse, $groups['attr'], $groups);
//
//        dd($item->getData(), $config->getTable($tableName)->getProperties());
//        $core = new Core();
//        $instance = (new Instance($core, $item->getKey()));

//        dd($workflow, $table);
//        $item = (array)$item;
            return [
            'instance' => $instance??null,
            'workflowEnabled' => (bool)$workflow, // comes from config
            'workflow' => $workflow,
            'key' => $item->getKey(),
            'tableName' => $tableName,
            'pixieCode' => $pixieCode,
            'row' => $item,
            'translatable' => $table->getTranslatable(),
            'item' => $item, // redundant! use row for data!
            'columns' => array_keys((array)$item),
            'groups' => $groups,
            'config' => $conf
        ];

    }

    private function flattenArray(array $array): array
    {
        foreach ($array as $row) {
            foreach ($row as $value) {
                if (is_iterable($value)) {

//                    $row[$var] = $this->flattenArray($value);  json_encode($value, JSON_UNESCAPED_SLASHES);
                }
            }
        }
        return $row;
    }

    #[Route('/browse/{pixieCode}/{tableName}.{_format}', name: 'pixie_browse')]
    public function browse(
        Request                      $request,
        Core $core,
        string                       $pixieCode,
        string                       $tableName,
        string                       $_format = 'html',
        #[MapQueryParameter] ?string $index = null,
        #[MapQueryParameter] ?string $value = null,
        #[MapQueryParameter] int     $limit = 50,
        #[MapQueryParameter] int     $offset = 0,
    ): Response
    {
        dd($core);
        // see SearchController for meili and the public-facing browse
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $where = [];
        if ($index) {
            $where[$index] = $value ?: null;
        }
        $kv->select($tableName);
        $keyName = $kv->getPrimaryKey($tableName);

        $iterator = $kv->iterate($tableName, $where);
//        foreach ($iterator as $key => $item) {
//            dd($item);
//        }


        $table = $kv->getTable($tableName);
        // @todo: refactor to pass properties to datatables
        foreach ($table->getProperties() as $property) {
            $columns[] = $property->getCode();
        }
//        if ($firstItem = $iterator->current()) {
////            dd($firstRow, $firstItem);
//
//            $columns = array_keys((array)$firstItem->getData());
//            $iterator = $kv->iterate($tableName, $where);
////            $iterator->rewind();
//        } else {
//            $columns = ['value'];
//        }
//        dd($where, $firstRow, $columns);

        // @todo: flatten nested json, apply view, column rules, etc.
        if ($_format == 'json') {
            $flattenRows = [];
            $idx = 0;
            foreach ($iterator as $item) {
                $row = (array)$item->getData();
//                assert($row, "Invalid data in $key " . $kv->getFilename());
                $idx++;
                foreach ($row as $var => $value) {
                    if ($var == $keyName) {
                        $row[$var] = sprintf(
                            "<a href='%s'>%s</a>",
                            $this->urlGenerator->generate('pixie_show_record', [
                                'pixieCode' => $pixieCode,
                                'tableName' => $tableName,
                                'key' => $value
                            ]), $value
                        );
                    } elseif (is_iterable($value)) {
                        $row[$var] = json_encode($value, JSON_UNESCAPED_SLASHES);
                    }
                }
                $flattenRows[] = $row;
                if ($limit && ($idx >= $limit)) {
                    break;
                }
            }
            return new JsonResponse($flattenRows);
        }
        if (in_array('marking', $columns)) {
            array_unshift($columns, $keyName, 'marking');
        }
        array_unshift($columns, $keyName);
        $columns = array_unique($columns);
        $iterator->rewind();

//        $rows = [];
//        foreach ($iterator as $key => $item) {
//            $rows[$key] =  $item->getData();
//        }
//        foreach ($kv->iterate($tableName, $where) as $row) {
//            dd($row);
//        }

        // there may be a better way.
        $remoteUrl = $this->urlGenerator->generate(
            $request->get('_route'),
            [...$request->get('_route_params'),
                ...$request->query->all(),
                '_format' => 'json']
        );
        $columns = array_values(array_unique($columns));
        // see kaggle for inspiration, https://www.kaggle.com/datasets/shivamb/real-or-fake-fake-jobposting-prediction/data
        return $this->render('@SurvosPixie/pixie/browse.html.twig', [
            'pixieCode' => $pixieCode,
            'tableName' => $tableName,
//            'kv' => $kv, // avoidable?/
            'remoteUrl' => $remoteUrl,
            'iterator' => [], // $firstRow ? $iterator : [],
            'keyName' => $keyName,
            'columns' => $columns,
            'filename' => $kv->getFilename(),
            'kv'=>$kv
        ]);

    }

    #[Route('/{pixieCode}/home', name: 'pixie_homepage', options: ['expose' => true])]
    #[Route('/{pixieCode}', name: 'pixie_overview')]
    public function info(
        string                   $pixieCode,
        ?Config $config = null,
        #[MapQueryParameter] int $limit = 100
    ): Response
    {
        $ctx = $this->pixieService->getReference($pixieCode);
        $countsByCore = $this->pixieService->getCountsByCore();
        foreach ($countsByCore as $code => $count) {
            $core = $this->pixieService->getCoreInContext($ctx, $code);
            $rows = $ctx->repo(Row::class)->findBy(['core' => $core], [], 50);
            assert($core, "Missing core $code");

            $data[$code] = $rows; // $this->rowRepository->findBy(['core.id' => $code], [], $limit);
        }

//        dump($em->getConnection()->getParams());
//        dd($this->pixieEntityManager->getConnection()->getDriver(), $this->pixieEntityManager->getConnection());
//        dd($em->getRepository(Row::class)->count());
//        dd($this->pixieEntityManager->getRepository(Row::class)->count());
//        dd($this->rowRepository->count());

        // order?
        $cores = [];
        foreach ($ctx->repo(Core::class)->findAll() as $core) {
            $cores[] = $core;
//            dd($core);
        }
        $tables = [];

//        return $this->render('@SurvosPixie/pixie/overview.html.twig', [
        return $this->render('@SurvosPixie/pixie/info.html.twig', [
            'data' => $data??null,
            'countsByCore' => $countsByCore,
//            'data' => $data,
            'limit' => $limit,
//            'kv' => $kv,
        // @todo: harmonize cores and tables
            'cores' => $cores,
            'tables' => $tables,
            'pixieCode' => $pixieCode,
            'config' => $ctx->config
            ]);
    }

    #[Route('/schemaOLD/{pixieCode}', name: 'pixie_schema_OLD')]
    #[Template('@SurvosPixie/pixie/schema.html.twig')]
    public function schemaOLD(
        string                   $pixieCode,
    ): array
    {
        // @todo: make automatic!
        $config = $this->pixieService->selectConfig($pixieCode);

        // the actual schema.  From the museum point of view, maybe not that interesting.
//        $pixieConn = $this->pixieEntityManager->getConnection();
//        $fromSchemaManager = $pixieConn->createSchemaManager();
//        $fromSchema = $fromSchemaManager->introspectSchema();
//        dd($fromSchema);



        $cores = [];
        foreach ($this->coreRepository->findAll() as $core) {
            $cores[$core->code] = $core;
        }
            return [
                'owner' => $config->getOwner(),
//            'kv' => $this->pixieService->getStorageBox($pixieCode),
            'config' => $config,
            'pixieCode' => $pixieCode,
                'cores' => $cores,
        ];
    }

    #[Route('/translations/{pixieCode}', name: 'pixie_translations')]
    #[Template('@SurvosPixie/pixie/translations.html.twig')]
    public function translations(
        string                   $pixieCode,
        #[MapQueryParameter] ?string $marking = null,
        #[MapQueryParameter] int $limit = 100
    ): Response|array
    {
        $ctx = $this->pixieService->getReference($pixieCode);
        $rows = [];
        foreach ([Str::class, StrTranslation::class] as $class) {
            $rows[$class] = $ctx->repo($class)->findBy([], [], 10);
        }
        return [
            'tableName' => 'str', // @todo: move pixie metadata to bundle
            'translationColumn' => 'trans', // in 'str'
//            'table' => $kv->select('str'),
//            'kv' => $kv,
            'data' => $this->strRepository->findBy([], [], 200),
            'config' => $config,
            'pixieCode' => $pixieCode,
        ];
    }

    #[Route('/images/{pixieCode}', name: 'pixie_images')]
    #[Template('@SurvosPixie/pixie/images.html.twig')]
    public function images(
        string                   $pixieCode,
        #[MapQueryParameter] int $limit = 100,
    ): array
    {
        $config = $this->pixieService->selectConfig($pixieCode);

        return [
            'images' => $this->originalImageRepository->findBy([], [], $limit),
            'config' => $config,
            'pixieCode' => $pixieCode,
        ];
    }

    private function getChartData(Property $property, string $tableName, StorageBox $kv, int $limit=100): ?array
    {
        $locale = $this->requestStack->getCurrentRequest()->getLocale();
        $kv->select(PixieInterface::PIXIE_STRING_TABLE);

        if (!$indexName = $property->getIndex()) {
            return null;
        }
        // primary is required and maybe min/max?
        if ($indexName === 'PRIMARY') {
            return null;
        }
        $chartBuilder = $this->chartBuilder;
        $indexName = $property->getCode();
        $labels = [];
        $values = [];

        $counts = $kv->getCounts($indexName, $tableName, $limit);
        if (count($counts) === 0) {
            return null;
        }
        $pks = array_map(fn($x) => $x['value'], $counts);
        try {
            $translatedStrings  =  $kv->iterate(
                pks: $pks
            );
        } catch (\Exception $e) {
            $translatedStrings = [];
        }

        $tStr = [];
        foreach ($translatedStrings as $tKey => $tItem) {
            $tStr[$tItem->hash()] = $tItem->text();
        }
        foreach ($counts as $count) {
            $value = $count['value'];
            $labels[] = $tStr[$value] ?? $value; // the property name
            $values[] = $count['count'];
            // @todo: composer require phpcolor/bootstrap-colors
            $colors[] = sprintf('rgb(%d, %d, %d)',
                random_int(0, 255),
                random_int(0, 255),
                random_int(0, 255)
            );
        }
        if (!$chartBuilder) {
            throw new \Exception("composer require symfony/ux-chartjs");
        }
        $chart = $chartBuilder->createChart(
            str_contains($indexName, 'year') ? Chart::TYPE_LINE :
                Chart::TYPE_PIE
        );

        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $indexName . "/$tableName",
                    'backgroundColor' => $colors,
                    'borderColor' => 'rgb(255, 99, 132)',
                    'data' => $values,
                ],
            ],
        ]);

        return [
            'chart' => $chart,
            'counts' => $counts
        ];

    }

    #[Route('/table/{pixieCode}/{tableName}', name: 'pixie_table')]
    #[Template('@SurvosPixie/pixie/graphs.html.twig')]
    public function tableCharts(
        string                   $pixieCode,
        string                   $tableName,
        #[MapQueryParameter] int $limit = 25
    ): array
    {
        $ctx = $this->pixieService->getReference($pixieCode);
        $this->pixieService->selectConfig($pixieCode);
//        $core = $this->coreRepository->find($tableName);
        $core = $this->coreService->getCore($tableName);
        $count = $this->rowRepository->count(['core' => $core]);
//        dd($count, $tableName, $limit);
//        $counts = $this->rowRepository->getCounts('core');
//        dd($counts);
        // row or instance?
        $count = $core->getRows()->count();
        // now core, not table!
        $pixieFilename = $this->pixieService->getPixieFilename($pixieCode);
        assert(file_exists($pixieFilename), "Missing $pixieFilename");

        // we don't need kv anymore, just use doctrine.
        $entityClass = Row::class;
        $entityClass = 'Survos\\PixieBundle\\Entity\\' . ucfirst($tableName);
        assert(class_exists($entityClass), "Invalid class $entityClass");
        $repo = $this->pixieEntityManager->getRepository($entityClass);


        $first = $repo->createQueryBuilder('r')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
//        $kv = $this->pixieService->getStorageBox($pixieCode);
        {
//            $count = $kv->count($tableName);
//            dd($tableName, $count);
            $tableData = [
                'first' => $first,
                'count' => $repo->count(), // APPROX??
            ];

            $charts = [];
            // @todo: new security with entities.
            $table = $this->pixieEntityManager->getConnection();
//            $tableSchema = $kv->inspectSchema()[$tableName];
            if (0)
            foreach ($table->getProperties() as $property) {
                if ($condition = $property->getSetting('security')) {
                    if (!$this->isGranted($condition)) {
                        continue;
                    }
                }

                // @todo: hide admin properties
//                dd($tableSchema, $property, $table->getProperties());
                try {
                    $chartData = $this->getChartData($property, $tableName, $kv, limit: $limit);
                    if ($chartData) {
                        $charts[$property->getCode()] = $chartData;
                    }
                } catch (\Exception) {
                    // probably a migration is needed.
                }
            }
            $tableData['charts'] = $charts;
        }
//        dd($tables);

        return [
            'pixieCode' => $pixieCode,
            'tableName'=>$tableName,
//            'kv' => $kv, // avoidable?/
            'tableData' => $tableData
        ];

    }

    #[Route('/import/{pixieCode}', name: 'pixie_import')]
    public function import(PixieService             $pixieService,
                           PixieImportService       $pixieImportService,
                           string                   $pixieCode,
                           #[MapQueryParameter] int $limit = 0,
    ): Response
    {
        $purgeFirst = $pixieImportService->purgeBeforeImport;
        $pixie = $pixieService->getStorageBox(
            $pixieCode,
            destroy: $purgeFirst,
            createFromConfig: true
        );
//        dd($pixie->getTables(), $purgeFirst);

        $pixieImportService->import($pixieCode, null, limit: $limit, kv: $pixie, overwrite: true);
        return $this->redirectToRoute('pixie_homepage', [
            'pixieCode' => $pixieCode
        ]);
    }
}
