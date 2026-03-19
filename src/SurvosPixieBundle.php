<?php

/** generated from /home/tac/ca/survos/packages/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\PixieBundle;

use Doctrine\DBAL\Schema\Metadata\MetadataProvider;
use Survos\CoreBundle\Traits\HasAssetMapperTrait;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\PixieBundle\Command\IterateCommand;
use Survos\PixieBundle\Command\PixieBabelBrowseCommand;
use Survos\PixieBundle\Command\PixieBabelEnsureCommand;
use Survos\PixieBundle\Command\PixieBabelSyncCommand;
use Survos\PixieBundle\Command\PixieBrowseCommand;
use Survos\PixieBundle\Command\PixieExportCommand;
use Survos\PixieBundle\Command\PixieInfoCommand;
use Survos\PixieBundle\Command\PixieImportCommand;
use Survos\PixieBundle\Command\PixieIndexCommand;
use Survos\PixieBundle\Command\PixieIngestCommand;
use Survos\PixieBundle\Command\PixieMakeCommand;
use Survos\PixieBundle\Command\PixieMediaCommand;
use Survos\PixieBundle\Command\PixieMeiliSettingsCommand;
use Survos\PixieBundle\Command\PixieMigrateCommand;
use Survos\PixieBundle\Command\PixiePrepareCommand;
use Survos\PixieBundle\Command\PixiePrepareSimpleCommand;
use Survos\PixieBundle\Command\PixieRowBrowseCommand;
use Survos\PixieBundle\Command\PixieSchemaDumpCommand;
use Survos\PixieBundle\Command\PixieSchemaSyncCommand;
use Survos\PixieBundle\Command\PixieStatsShowCommand;
use Survos\PixieBundle\Command\PixieSyncCommand;
use Survos\PixieBundle\Command\PixieSyncIndexesCommand;
use Survos\PixieBundle\Command\PixieTermsExtractCommand;
use Survos\PixieBundle\Command\PixieTermsIngestCommand;
use Survos\PixieBundle\Command\PixieTermsShowCommand;
use Survos\PixieBundle\Command\PixieTranslateCommand;
use Survos\PixieBundle\Compiler\PixieMeiliIndexPass;
use Survos\PixieBundle\Components\DatabaseComponent;
use Survos\PixieBundle\Components\RowComponent;
use Survos\PixieBundle\Controller\PixieBrowseController;
use Survos\PixieBundle\Controller\PixieController;
use Survos\PixieBundle\Controller\PixieDashboardController;
use Survos\PixieBundle\Controller\PixiePrinterController;
use Survos\PixieBundle\Controller\PixieTermsController;
use Survos\PixieBundle\Controller\SearchController;
use Survos\PixieBundle\DataCollector\PixieDataCollector;
use Survos\PixieBundle\Debug\TraceableStorageBox;
use Survos\PixieBundle\Dto\Attributes\Mapper as MapperAttr;
use Survos\PixieBundle\EventListener\CsvHeaderEventListener;
use Survos\PixieBundle\EventListener\PixieControllerEventListener;
use Survos\PixieBundle\EventListener\PixieEnhanceRecordListener;
use Survos\PixieBundle\EventListener\PixiePostLoadListener;
use Survos\PixieBundle\EventListener\TranslationRowEventListener;
use Survos\PixieBundle\Import\Ingest\CsvIngestor;
use Survos\PixieBundle\Import\Ingest\JsonIngestor;
use Survos\PixieBundle\Menu\PixieItemMenu;
use Survos\PixieBundle\Menu\PixieMenu;
use Survos\PixieBundle\Repository\CoreDefinitionRepository;
use Survos\PixieBundle\Repository\CoreRepository;
use Survos\PixieBundle\Repository\FieldDefinitionRepository;
use Survos\PixieBundle\Repository\FieldRepository;
use Survos\PixieBundle\Repository\OriginalImageRepository;
use Survos\PixieBundle\Repository\InstRepository;
use Survos\PixieBundle\Repository\RowRepository;
use Survos\PixieBundle\Repository\TermRepository;
use Survos\PixieBundle\Repository\TermSetRepository;
use Survos\PixieBundle\Schema\SchemaProvider;
use Survos\PixieBundle\Schema\YamlSchemaSynchronizer;
use Survos\PixieBundle\Service\CoreService;
use Survos\PixieBundle\Service\DtoMapper;
use Survos\PixieBundle\Service\DtoRegistry;
use Survos\PixieBundle\Service\Enhance\PixieEnhancer;
use Survos\PixieBundle\Service\Enhance\PixieRenameApplier;
use Survos\PixieBundle\Service\EventQueryService;
use Survos\PixieBundle\Service\ImportHandler;
use Survos\PixieBundle\Service\IndexModelResolver;
use Survos\PixieBundle\Service\LibreTranslateService;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\MeiliIndexer;
use Survos\PixieBundle\Service\MeiliSettingsBuilder;
use Survos\PixieBundle\Service\PixieBabelBrowser;
use Survos\PixieBundle\Service\PixieConfigRegistry;
use Survos\PixieBundle\Service\PixieConvertService;
use Survos\PixieBundle\Service\PixieDocumentProjector;
use Survos\PixieBundle\Service\PixieEntityManagerProvider;
use Survos\PixieBundle\Service\PixieEntityMetadataProvider;
use Survos\PixieBundle\Service\PixieImportOrchestrator;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieMeiliSettingsFromConfig;
use Survos\PixieBundle\Service\PixieSchemaManager;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieTranslationService;
use Survos\PixieBundle\Service\ReferenceService;
use Survos\PixieBundle\Service\RelationService;
use Survos\PixieBundle\Service\RowDocumentExpander;
use Survos\PixieBundle\Service\RowIngestor;
use Survos\PixieBundle\Service\RowToIndexModelMapper;
use Survos\PixieBundle\Service\SchemaViewService;
use Survos\PixieBundle\Service\SqlViewService;
use Survos\PixieBundle\Service\StatsCollector;
use Survos\PixieBundle\Service\TermCodeGenerator;
use Survos\PixieBundle\Service\TermSpecParser;
use Survos\PixieBundle\Service\TranslationResolver;
use Survos\PixieBundle\Twig\TwigExtension;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Twig\Environment;

class SurvosPixieBundle extends AbstractBundle implements CompilerPassInterface
{
    use HasAssetMapperTrait;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Only register if MeiliBundle is installed
//        if (class_exists(MeiliService::class)) {
        $container->addCompilerPass(new PixieMeiliIndexPass());
//        }

        // Autoconfigure DTO classes annotated with #[Mapper] -> tag "pixie.dto"
        $container->registerAttributeForAutoconfiguration(
            MapperAttr::class,
            static function (Definition $definition, MapperAttr $attr, \ReflectionClass $ref): void {
                $definition->addTag('pixie.dto', [
                    'priority' => $attr->priority,
                    'when' => $attr->when,
                    'except' => $attr->except,
                    'cores' => $attr->cores,
                ]);
            }
        );

        // If you still want this class as a compiler pass for other reasons, keep it.
        // Otherwise, you can drop the addCompilerPass($this) call entirely.
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        // Intentionally no-op (remove dd/experiments).
        // You can add other pass-time tweaks here later if needed.
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        foreach ([
                     \Survos\PixieBundle\Dto\Generic\Obj::class,
                     // add more bundle DTOs here as you create them:
                     // \Survos\PixieBundle\Dto\Cleveland\Obj::class,
                 ] as $dtoClass) {
            $builder->register($dtoClass)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(false);
        }

        // repos are not public, but do need the tag
        foreach ([CoreRepository::class,
                     TermSetRepository::class,
                     TermRepository::class,
                     FieldRepository::class,
                     FieldDefinitionRepository::class,
                     OriginalImageRepository::class,
                     RowRepository::class,
                     InstRepository::class,
                     CoreDefinitionRepository::class] as $class) {
            $builder->register($class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('doctrine.repository_service')//                ->setPublic(true)
            ;

        }

        // Core services
        foreach ([
                     MetadataProvider::class,
                     IndexModelResolver::class,
                     RowToIndexModelMapper::class,
                     SchemaProvider::class,
                     PixieSchemaManager::class,
                     PixieEntityMetadataProvider::class,
                     TermSpecParser::class,
                     TermCodeGenerator::class,
                     PixieMeiliSettingsFromConfig::class,
                     PixieConfigRegistry::class,
                     PixieBabelBrowser::class,
                     PixieImportOrchestrator::class,
                     PixieEnhancer::class,
                     PixieEnhanceRecordListener::class,
                     PixieRenameApplier::class,
                     StatsCollector::class,
                     PixieService::class,
                     PixieImportService::class,
                     PixieEntityManagerProvider::class,
                     CoreService::class,
                     LocaleContext::class,
                     MeiliSettingsBuilder::class,
                     JsonIngestor::class,
                     CsvIngestor::class,
                     YamlSchemaSynchronizer::class,
                     PixieDocumentProjector::class,
                     RowDocumentExpander::class,
                     EventQueryService::class,
                     TranslationResolver::class,
                     PixieTranslationService::class,
                     LibreTranslateService::class,
                     MeiliIndexer::class,
                     PixieConvertService::class,
                     ReferenceService::class,
                     RelationService::class,
                     RowIngestor::class,
                     SchemaViewService::class,
                     SqlViewService::class,
                     DtoMapper::class,
                     DtoRegistry::class,
                     DatabaseComponent::class,
                     RowComponent::class,
                 ] as $svc) {
            $builder->register($svc)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }


        $connectionName = $config['babel']['connection'] ?? 'default';
        $serviceId = sprintf('doctrine.dbal.%s_connection', $connectionName);

        $builder->getDefinition(TranslationResolver::class)
            ->setArgument('$connection', new Reference($serviceId));

        // PixieService args
        $builder->getDefinition(PixieImportService::class)
            ->setArgument('$logger', new Reference('logger'))
            ->setArgument('$purgeBeforeImport', $config['purge_before_import']);

        $builder->getDefinition(PixieConvertService::class)
            ->setArgument('$logger', new Reference('logger'))
            ->setArgument('$purgeBeforeImport', $config['purge_before_import']);

        $builder->getDefinition(PixieService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$isDebug', $config['debug'])
            ->setArgument('$dataRoot', $config['data_root'])
            ->setArgument('$configDir', $config['config_dir'])
            ->setArgument('$dbDir', $config['db_dir'])
            ->setArgument('$bundleConfig', $config)
            ->setArgument('$stopwatch', new Reference('debug.stopwatch'))
            ->setArgument('$serializer', new Reference('serializer'))
            ->setArgument('$logger', new Reference('logger'));

        // Twig extension (if Twig is installed)
        if (class_exists(Environment::class)) {
            $builder
                ->setDefinition('survos.pixie_bundle.twig_extension', new Definition(TwigExtension::class))
                ->setArgument('$requestStack', new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->addTag('twig.extension')
                ->setAutoconfigured(true)
                ->setAutowired(true)
                ->setPublic(false);
        }

        foreach ([PixieBrowseController::class,
                     PixieTermsController::class,
                     PixiePrinterController::class,
                     PixieDashboardController::class,
                     PixieController::class
                 ] as $controllerClass) {
//        foreach ([PixieController::class, PixiePrinterController::class, PixieDashboardController::class] as $controllerClass) {
            // Controllers
            $builder->autowire($controllerClass)
                ->addTag('controller.service_arguments')
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);

        }

        $builder->autowire(SearchController::class)
            ->addTag('controller.service_arguments')
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);

        // Data collector
        $builder->autowire(PixieDataCollector::class)
            ->setArgument('$pixieService', new Reference(PixieService::class))
            ->addTag('data_collector', ['template' => '@SurvosPixie/DataCollector/pixie_debug_profile.html.twig'])
            ->setPublic(true);

//        // StorageBox helpers
//        foreach ([StorageBox::class, TraceableStorageBox::class] as $storageBoxClass) {
//            $builder->register($storageBoxClass)
//                ->setAutowired(true)
//                ->setAutoconfigured(true)
//                ->setArgument('$logger', new Reference('logger'))
//                ->setPublic(true);
//        }
//
        // Event listeners
        foreach ([CsvHeaderEventListener::class, PixiePostLoadListener::class, PixieControllerEventListener::class] as $listener) {
            $builder->register($listener)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        // Abstract base commands — must be registered so #[Required] setters are called on subclasses
        $builder->register(\Survos\PixieBundle\Command\PixieCommand::class)
            ->setAbstract(true)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        // Commands
        foreach ([
                     PixieTermsShowCommand::class,
                     PixieImportCommand::class,
                     PixieTermsExtractCommand::class,
                     PixieTermsIngestCommand::class,
                     PixieBabelEnsureCommand::class,
                     PixieBabelSyncCommand::class,
                     PixieBabelBrowseCommand::class,
                     PixieSyncIndexesCommand::class,
                     PixieStatsShowCommand::class,
                     PixieMeiliSettingsCommand::class,
                     PixieMigrateCommand::class,
                     PixieInfoCommand::class,
                     PixieMediaCommand::class,
                     PixieTranslateCommand::class,
                     PixieBrowseCommand::class,
                     PixiePrepareSimpleCommand::class,
//                     PixiePrepareCommand::class,
//                     PixieImportCommand::class,
                     PixieExportCommand::class,
                     PixieIngestCommand::class,
                     PixieSchemaDumpCommand::class,
                     PixieSchemaSyncCommand::class,
                     IterateCommand::class,
                     PixieIndexCommand::class,
                     PixieSyncCommand::class,
                     PixieRowBrowseCommand::class,
                     PixieMakeCommand::class,
                 ] as $commandClass) {
            $builder->autowire($commandClass)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }


        $builder->register(PixieConfigRegistry::class)
            ->setArgument('$denormalizer', new Reference(DenormalizerInterface::class))
            ->setArgument('$pixiesConfig', $config['pixies'] ?? []);


    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root
            ->children()
            // Use a filename extension compatible with your PixieService expectation
            ->scalarNode('extension')->defaultValue('db')->info("pixie DB filename extension, e.g. 'db'")->end()
            ->scalarNode('db_dir')
                ->defaultValue('%env(default::APP_DATA_DIR)%/pixie')
                ->info("Directory for pixie SQLite DB files. Defaults to APP_DATA_DIR/pixie. Set to a shared path (NFS, S3 mount, Bunny) so md, mus, ssai, zm can all read the same files.")
            ->end()
            ->scalarNode('data_root')->defaultValue('data')->info("root for csv/json data")->end()
            ->scalarNode('transport')->defaultNull()->info("default messenger transport for iterate")->end()
            ->booleanNode('debug')->defaultFalse()->info("turn on profiler hooks (kernel.debug?)")->end()
            ->booleanNode('purge_before_import')->defaultFalse()->end()
            ->integerNode('limit')->defaultValue(0)->info("default limit for import/index/translate")->end()
            ->scalarNode('config_dir')->defaultValue('config/packages/pixie')->end()
            ->end();

        $this->addPixiesSection($root);
    }

    private function addPixiesSection(ArrayNodeDefinition $rootNode): void
    {
        $children = $rootNode->children();

        $this->addCoresSection($children);
        $this->addTablesSection($children, 'templates');
        $this->addPixiesSectionChildren($children);

        $children->end();
    }

    private function addCoresSection(NodeBuilder $children): void
    {
        $children
            ->arrayNode('cores')
            ->arrayPrototype()
            ->children()
            ->scalarNode('icon')->end()
            ->scalarNode('icon_class')->end()
            ->end()
            ->end()
            ->end();
    }

    private function addPixiesSectionChildren(NodeBuilder $children): void
    {
        $pixieRoot = $children
            ->arrayNode('pixies')
            ->useAttributeAsKey('code')
            ->arrayPrototype()
            ->children();

        $pixieRoot->scalarNode('version')->defaultValue('1.1')->end();
        $pixieRoot->scalarNode('code')->defaultNull()->end();
        $pixieRoot->scalarNode('type')->defaultValue('museum')->end();

        $pixieRoot->arrayNode('files')->scalarPrototype()->end()->end();

        $pixieRoot->arrayNode('members')
            ->arrayPrototype()
            ->children()
            ->scalarNode('email')->defaultNull()->end()
            ->scalarNode('roles')->defaultNull()->end()
            ->end()
            ->end()
            ->end();

        $this->addTablesSection($pixieRoot);
        $this->addBabelSection($pixieRoot);
        $this->addSourceSection($pixieRoot);
    }

    private function addTablesSection(NodeBuilder $parent, string $key = 'tables'): void
    {
        $tableRoot = $parent->arrayNode($key)
            ->useAttributeAsKey('name')
            ->arrayPrototype()
            ->children()
            ->integerNode('total')->defaultNull()->info("if total is known for progress bar")->end()
            ->booleanNode('has_images')->defaultNull()->info("if images display thumbnail")->end()
            ->scalarNode('extends')->defaultNull()->info("inherits table data from templates")->end()
            ->arrayNode('rules')->scalarPrototype()->end()->end()
            ->scalarNode('workflow')->end()
            ->scalarNode('parent')->defaultNull()->info("related parent table in 1:N")->end()
            ->arrayNode('translatable')->scalarPrototype()->end()->end()
            ->arrayNode('facets')->scalarPrototype()->end()->end()
            ->arrayNode('extras')->scalarPrototype()->end()->end()
            ->arrayNode('uses')->scalarPrototype()->end()->info("use definitions from templates['internal']")->end()
            ->arrayNode('properties')->scalarPrototype()->end()->end()
            ->end()
            ->end();
    }

    private function addSourceSection(NodeBuilder $pixieRoot): void
    {
        $source = $pixieRoot
            ->arrayNode('source')
            ->children();

        $this->addGitSection($source);
        $this->addBuildSection($source, 'build');
        $this->addBuildSection($source, 'make');

        $source
            ->scalarNode('instructions')->end()
            ->scalarNode('units')->defaultValue('cm')->info("mm or cm")->end()
            ->scalarNode('label')->end()
            ->scalarNode('flickr_album_id')->end()
            ->scalarNode('approx_image_count')->end()
            ->scalarNode('license')->info("e.g. 'CC BY-NC-SA'")->end()
            ->scalarNode('moderation')->defaultValue('all')->info("moderate before flickr upload (none|all)")->end()
            ->scalarNode('description')->end()
            ->scalarNode('origin')->isRequired()->info("data source: api, github, musdig")->end()
            ->scalarNode('country')->info("2-letter country code")->end()
//            ->scalarNode('locale')->end() // ack! Deprecated
            ->scalarNode('notes')->end()
            ->scalarNode('dir')->info('defaults to <projectDir>/data')->end()
            ->arrayNode('links')
            ->children()
            ->scalarNode('facebook')->end()
            ->scalarNode('twitter')->end()
            ->scalarNode('github')->end()
            ->scalarNode('instagram')->end()
            ->scalarNode('flickr')->end()
            ->scalarNode('api')->end()
            ->scalarNode('contact')->end()
            ->scalarNode('license')->end()
            ->scalarNode('web')->end()
            ->scalarNode('website')->end()
            ->scalarNode('search')->end()
            ->scalarNode('info')->end()
            ->end()
            ->end()
            ->arrayNode('ignore')
            ->beforeNormalization()->ifString()->then(fn($v) => [$v])->end()
            ->scalarPrototype()->end()
            ->end()
            ->scalarNode('include')->end()
            ->end()->end();
    }


    private function addBabelSection(NodeBuilder $root): void
    {
        $root
            ->arrayNode('babel')
            ->info('Babel / translation configuration for this pixie')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('source')
            ->info('Source locale for Babel strings (default: en)')
            ->defaultValue('en')
//            ->cannotBeEmpty() // really, we should also allow something like @locale, to mean look for the $locale field in this row
            ->end()
            ->arrayNode('targets')
            ->info('Target locales to translate into (defaults to enabled_locales minus source_locale)')
            ->scalarPrototype()->cannotBeEmpty()->end()
            ->defaultValue([])
            ->end()
            ->scalarNode('engine')
            ->info('Preferred translation engine (e.g. libre, deepl)')
            ->defaultNull()
            ->end()
            ->end()
            ->end();
    }


    private function addGitSection(NodeBuilder $sourceRoot): void
    {
        $sourceRoot
            ->arrayNode('git')
            ->children()
            ->scalarNode('repo')->end()
            ->booleanNode('lfs')->defaultFalse()->end()
            ->scalarNode('lsf_include')->end()
            ->end()
            ->end();
    }

    private function addBuildSection(NodeBuilder $sourceRoot, string $nodeName = 'build'): void
    {
        $sourceRoot
            ->arrayNode($nodeName)
            ->arrayPrototype()
            ->children()
            ->scalarNode('action')->end()
            ->scalarNode('source')->end()
            ->scalarNode('target')->end()
            ->scalarNode('unzip')->end()
            ->scalarNode('cmd')->end()
            ->end()
            ->end()
            ->end();
    }

    public function getPaths(): array
    {
        $dir = realpath(__DIR__ . '/../assets/');
        assert($dir && file_exists($dir), 'asset path must exist for the assets in ' . __DIR__);
        return [$dir => '@survos/pixie'];
    }
}
