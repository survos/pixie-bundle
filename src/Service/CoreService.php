<?php


namespace Survos\PixieBundle\Service;

use App\Entity\CategoryType;
use App\Entity\ListItem;
use App\Entity\Member;
use App\Entity\OldCore;
use App\Entity\ProjectCoreInterface;
use App\Entity\ProjectInterface;
use App\Entity\Spreadsheet;
use App\Entity\User;
use Survos\PixieBundle\Model\ImportSettings;
use App\Repository\InstanceCategoryRepository;
use App\Service\AppService;
use App\Service\LibreTranslateService;
use App\Service\TranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Limenius\Liform\Liform;
use Psr\Log\LoggerInterface;
use Survos\GridGroupBundle\CsvSchema\Parser;
use Survos\GridGroupBundle\Model\Schema;
use Survos\PixieBundle\Entity\Category;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\CoreInterface;
use Survos\PixieBundle\Entity\Field\CategoryField;
use Survos\PixieBundle\Entity\Field\DatabaseField;
use Survos\PixieBundle\Entity\Field\Field;
use Survos\PixieBundle\Entity\Field\FieldInterface;
use Survos\PixieBundle\Entity\Field\RelationField;
use Survos\PixieBundle\Entity\FieldSet;
use Survos\PixieBundle\Entity\Instance;
use Survos\PixieBundle\Entity\InstanceCategory;
use Survos\PixieBundle\Entity\InstanceInterface;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Repository\CoreRepository;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function Symfony\Component\String\u;

class CoreService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RelationService $relationService,
//        private ProjectConfigFactoryService $projectConfigFactory,
        private FormFactoryInterface                          $formFactory,
//        private readonly CoreRepository $coreRepository,
        private array                             $cores = []

    ) {
    }

    private ImportSettings $importSettings;

//    public function updateCounts(Core $projectCore)
//    {
//        $instanceCount = $this->getInstanceRepo($projectCore)->createQueryBuilder('i')
//            ->select('COUNT(i.id)')
//            ->where('i.project = :project')
//            ->setParameter('project', $projectCore->getProject())
//            ->getQuery()
//            ->getSingleScalarResult();
//        $projectCore->setEntityCount($instanceCount);
//        // update the types
//    }
//
    // hack to create project cores and relation types, for easier importing and testing.
    // @todo: move to a combined csv
    public function createDefaults(Spreadsheet $spreadsheet)
    {
    }





    public function persistWithProject(ProjectInterface $entity, ?Project $project = null)
    {
        $entity->setProject($project ?: $this->getProject());
        $this->entityManager->persist($entity);
    }

    /**
     * e.g. addToConfig(StorageLocation $basement, 'type', 'room') would get Category['type']['room'] and add this instance
     *
     * The config should be locked, since this is cached, no dynamic additions.
     */
    public function getConfig(Core $projectCore, string $configType, string $configInstanceKey): Category
    {
        static $moduleConfig = [];
        assert(false, " is the cache causing problems?");
        if (empty($moduleConfig)) {
            $configs = $projectCore->getProject()->getCategories(); // should be lazy, get all of them now for a single query

            foreach ($configs as $config) {
                // ['obj']['type']['artwork']
                $moduleConfig[$config->getCategoryTypeCode()][$config->getCoreCodeFromSheetName()][$config->getCode()] = $config;
            }
        }

        return $moduleConfig[$configType][$projectCore->getCoreCode()][$configInstanceKey];
        assert(false, $configInstanceKey, $moduleConfig, $configType, $projectCore->getCoreCode(), array_keys($moduleConfig));
        assert(false, $config, $configInstanceKey, $configType);
        assert(false); // bad data, need to handle exceptions.
    }

    /**
     * Gets a cached RelationField that was defined in the configuration
     */
    public function getExistingRelationField(Core $projectCore, string $code): RelationField
    {
        static $RelationFieldsCache = [];
        assert(false, " is the cache causing problems?");
        if (empty($RelationFields)) {
            $RelationFields = $projectCore->getProject()->getRelationFields(); // should be lazy, get all of them now for a single query
            foreach ($RelationFields as $rt) {
                $RelationFieldsCache[$rt->getLeftCore()->getCoreCode() . '.' . $rt->getCode()] = $rt;
            }
        }
        $key = sprintf("%s.%s", $projectCore->getCoreCode(), $code);
        //        assert(array_key_exists($coreCode = $projectCore->getCoreCode(), $RelationFieldsCache, ),
        //            sprintf("missing $coreCode in %s",
        //                join(',', array_keys($RelationFieldsCache))));
        //
        $coreRelations = $RelationFieldsCache; // [$projectCore->getCoreCode()];
        $keyExists = array_key_exists($key, $coreRelations);
        if (! $keyExists) {
            assert(false, $key, $coreRelations);
        }
        assert($keyExists, "Missing $key $code in " . join(',', array_keys($coreRelations)));
        return $coreRelations[$key];
        assert(false, $RelationFieldsCache);
    }


    public function getCategoryType(
        Core   $projectCore,
        string $categoryTypeCode,
        bool   $autoCreate = true,
        bool   $withRoots = true
    ): ?CategoryField {
        assert($categoryTypeCode, "cat type code must be a value, should get against types or dictionary");
        assert(false);

        dd($categoryTypeCode, $projectCore->getCode());
        // @todo: use doctrine @IndexBy to get the cateogryType

        if (! $projectCore->getCategoryTypes()->containsKey($categoryTypeCode)) {
            if ($autoCreate) {
                $categoryType = (new CategoryType($projectCore, $categoryTypeCode));
            //                $projectCore->addCategoryType($categoryType);

            //                                if ($withRoots) {
            //                                        $categoryTypeRootCategory = (new Category())
            //                                            ->setCode('root_' . $categoryTypeCode)
            //                                            ->setLabel($projectCore->getCoreCode() . ' ' . $categoryTypeCode . ' Root');
            //                                        $categoryType->addCategory($categoryTypeRootCategory);
            //                //                        $configSummary[$category] = [
            //                //                            'root' => $moduleConfigRoot->getId(),
            //                //                            'count' => 0
            //                //                        ];
            //                                    }
            } else {
                $categoryType = null;
            }
        } else {
            $categoryType = $projectCore->getCategoryType($categoryTypeCode);
        }

        return $categoryType;
    }

    public function getCategory(string $categoryCode, ?CategoryField $categoryField = null, bool $autoCreate = true, ?string $label=null): ?Category
    {
        // @todo: use doctrine @IndexBy to get the cateogryType
//        $category = $categoryType->getCategoryByCode($categoryCode);
//        assert(false, $categoryCode);
        $category = $categoryField->getCategoryByCode($categoryCode);
        $label = trim($label, '`');
        assert($label);
        if (! $category && $autoCreate) {
            assert($categoryField, "must pass categoryField if autocreate");
            if (!$label) {
                $label = u($categoryCode)->lower()->title(true)->toString();
            }
            $category = (new Category($categoryField, $categoryCode, label:  $label));

            $projectLocale = $categoryField->getProjectLocale();
            $category->setLabel($label, $projectLocale);
            $this->entityManager->persist($category->getTranslationEntity());
            $category->mergeNewTranslations();
//            dd($categoryCode, $label, $category->getLabel($locale), $category->getCurrentLocale(), $category->getDefaultLocale(), $categoryField->getProject()->getProjectLocale());
//            $label = u($categoryCode)->lower()->title(true)->toString();

        }
        //        $projectCore->addCategoryType($categoryType);
        return $category;
    }

    public function getCategoryFieldRoot(?CategoryField $categoryField=null, bool $autoCreate = true): Category
    {
        if (! $rootCategory = $categoryField->getRootCategory()) {
            if ($autoCreate) {
                $rootCategory = (new Category($categoryField, $categoryField->getInternalCode() . '_root', $categoryField->getLabel() . ' Root'));
                assert($categoryField, "you muast pass categoryField to autoCreate");
                $categoryField->addCategory($rootCategory);
            }
            assert($rootCategory->getCategoryField());
        }
        return $rootCategory;
    }

    public function createDatabaseFields(Core $projectCore)
    {
        $fieldSet = (new FieldSet(Field::TYPE_INTRINSIC, $projectCore));
        // we need to set the name from the alias somewhere
        foreach (Instance::DB_FIELDS as $DB_FIELD) {
            $field = $projectCore->getFieldByCode($DB_FIELD, DatabaseField::class, autoCreate: true, fieldDef: [
                'internalCode' => $DB_FIELD
            ])
                ->setAccessLevel(Member::PROJECT_VISITOR)
//                ->setLabel($this->translation->trans($DB_FIELD))
            ;
            assert($field::class == DatabaseField::class, $field::class . ' should be a database field ' . $field->getCode() . ' in ' . $projectCore->getCoreCode());
//                    assert($field::class == DatabaseField::class);
            assert($field->getCode());
            $fieldSet->addField($field);
//                    dd($field->getOrderIdx());
        }

    }

    public function getProjectCore(Project $project, string $coreCode, bool $autoCreate = true): ?Core
    {
        assert($coreCode, "core cannot be blank");
        assert(false);
        assert(in_array($coreCode, (new \ReflectionClass(CoreInterface::class))->getConstants()), "Invalid core " . $coreCode);
//        if (is_string($core)) {
//            $core = $this->getCoreByCode($coreCode = $core);
//            assert($core, "$coreCode not found in cores. " . join(',', array_keys($project->getProjectCores()->toArray())));
//        }
//        $coreCode = $core->code;
        // @todo: cache by class, code, etc.
        if (! $core = $project->getProjectCore($coreCode, false)) {
            //            $project->getProjectCores()->filter(fn(Core $core) => $core->getCore() === $core)?->first())
            if ($autoCreate) {
//                $core = (new Core($project, $coreCode));
//                $this->entityManager->persist($core);
//                $project->addProjectCore($core);
                // all pcs have some intrinic fields?
//                $this->createDatabaseFields($core);
            }
            // it's probably during an import, so it'll be active.
            if ($autoCreate) {
                $core
                    ->setIsEnabled(true);
            }
            assert($core, "Missing $coreCode in " . $project->getCode());
            $core->initNonPersisted();
        }

        return $core;

        //        // this no longer makes sense, since it should happen when type/source/etc are being created in Category (configTables??)
        //            assert(false);
        //            foreach ($core->getKeyListMap() as $key => $value) {
        //                $root = $this->appService->getFQCN($value);
        //                /** @var NestedListItem $rootInstance */
        //                $rootInstance = new $root;
        //                $rootInstance
        //                    ->setCode('root_', $core->code . '_' . $key)
        //                    ->setLabel($core->code . ' ' . $key . ' Root');
        //                $this->persistWithProject($rootInstance, $project);
        //            }
        //        $project->addProjectCore($core);
    }

    public function getInstanceFromObj(Core $projectCore, ?array $lookup, $objData): Instance
    {
//        dd($objData);
        $accessor = new PropertyAccessor();

        $isListItem = ($objData['configType'] ?? 'type') == 'list';
        if ($isListItem) {
            $coreClass = ListItem::class;
        }

        // for now, create the core instance and the generic instance.
        if (empty($lookup)) {
            //            assert(false, $objData, $projectCore);
        }
        // if there's no lookup OR it's not found, then create it.
        if (is_null($lookup) || ! $instance = $this->entityManager->getRepository(Instance::class)->findOneBy($lookup + [
            'projectCore' => $projectCore,
        ])) {
            // hackish
            //            if (!$isListItem) {
            //                $coreClass = $projectCore->getCore()->getFullEntityName();
            //            }
            //        }
            //
            //        if (!$instance) {
            // set the code or the UUID
            $instance = (new Instance())
                ->setImportData($objData);

            $projectCore->addInstance($instance);
            // this is handled later, since it's in objData
            //            if (array_key_exists('code', $lookup)) {
            //                $instance->setCode($lookup['code']);
            //            }

            //        }
            ////        return $instance;
            //
            //
            //
            ////        assert(false, $coreClass);
            //        if (is_null($lookup) || !$instance = $this->entityManager->getRepository($coreClass)->findOneBy($lookup))
            //        {

            // hack, for code
            $code = $objData['code'];
            /** @var InstanceInterface $instance */
            //            $coreInstance = (new $coreClass);
            //            $instance = (new Instance());
            // we don't have a projectCore, since the table name is the core.
            assert($instance::class == Instance::class);
            assert(is_string($code), "code is not a string: " . get_debug_type($code) . '/' . json_encode($objData));
            $instance
                ->setCode($code)
                ->setImportData($objData)
                ->setProject($projectCore->getProject());
            $this->entityManager->persist($instance);
            // should there be an instance list associated with the projectCore?
            // when persisting, the project keeps track of counts by class, which should be the same as in the PC.  PC needs type/status breakdown, though.
            // this is all instances, including list items
            if (! $isListItem) {
                $projectCore->incrementInstanceCount();
            }
        }

        foreach ($objData as $var => $val) {
            // look for relations, e.g. created_by.person: '@felguerez'.  Only relations have .
            // e.g., in a yaml file
            if (str_contains($var, '.')) {
                assert(false, "why is this here?");
                [$relationship, $rightCoreCode] = explode('.', $var);
                if (is_string($val)) {
                    $val = [$val];
                }
                foreach ($val as $rightInstanceCode) {
                    // add it to the existing 'rel', then processed later?
                    // queue up relationships to lookup later, after all the instances have been loaded.
                    $this->queueRelationship($instance, $relationship, $rightCoreCode, $rightInstanceCode);
                }
                continue; // need to make sure the relationship defaults and additional ones play nicely
            }

            switch ($var) {
                // if it's come in from the defaults, the 'rel' will be set
                case 'rel':
                    foreach ($val as $rightCoreCode => $relations) {
                        foreach ($relations as $relationCode => $rightInstanceCodes) {
                            if (is_string($rightInstanceCodes)) {
                                $rightInstanceCodes = [$rightInstanceCodes];
                            }

                            foreach ($rightInstanceCodes as $rightInstanceCode) {
                                $this->queueRelationship($instance, $relationCode, $rightCoreCode, $rightInstanceCode);
                            }
                        }
                    }
                    //                    dd($this->relationshipQueue);
                    break;

                case 'cat':
                    assert(false, "This should now be categoryField");
                    foreach ($val as $categoryTypeCode => $categoryCode) {
                        $categoryType = $projectCore->getCategoryType($categoryTypeCode, true);
                        $category = $categoryType->getCategoryByCode($categoryCode);
                        assert($category);
                        if (! $instanceCategory = $this->instanceCategoryRepository->findOneByInstanceAndCategory($instance, $category)) {
                            $instanceCategory = InstanceCategory::create($instance, $category);
                        }

                        //                        // this should be a factory...
                        //                        $instanceCategory = (new InstanceCategory($instance, $category));
                        //                                                $instance->addInstanceCategory($instanceCategory);
                    }

                    break;
                case 'list':
                    //                case 'relation':
                    //                    try {
                    //                        $config = $this->getConfig($projectCore, $var, $val);
                    //                    } catch (\Exception $exception) {
                    //
                    //                        // how to report typos.
                    ////                        throw new \Exception("error: $var '$val' is invalid ");
                    ////                            assert(false, $exception, $objData, $var, $val, $projectCore->getCoreCode());
                    //                        break;
                    //                    }
                    //
                    //                    assert($config);
                    ////                    assert(false, $config);
                    ///
                    ///
                    $instance->setTypeConfig($category); // or setConfig('type', $config)?
                    // this should be in @format?
                    break;

                    // probably not the right spot for this!
                case 'ref':
                case 'references':
                    //                    assert(false, $instance, $lookup, $objData, $this->pro);
                    break;

                case 'parent':
                    break;

                default:
                    assert($instance, "No instance");
                    try {
                        $accessor->setValue($instance, $var, $val);
                    } catch (\Exception $exception) {
                        assert(false, "Cannot set $var in object " . $instance::class . " " . json_encode($objData));
                    }
            }
        }

        return $instance;
    }

    private array $relationshipQueue = [];

    public function getRelationshipQueue(): array
    {
        return $this->relationshipQueue;
    }

    public function queueRelationship(ProjectCoreInterface $instance, string $relationship, string $rightCoreCode, string $rightInstanceCode)
    {
        $this->relationshipQueue[$instance->getCore()->getCoreCode()][$rightCoreCode][$rightInstanceCode][$relationship][] = $instance;
        return;

        // ideally, @felguerez, if "Manuel Felguerez", search by label, or optionally auto-add.
        if (u($rightInstanceCode)->startsWith('@')) {
            // look for an instance of the appropriate type
            // e.g. find('dali','person')
            $rightInstance = $this->findInstance(trim($rightInstanceCode, '@'), $coreCode);
            assert($rightInstance, "$rightInstanceCode not found in $coreCode");
        }
    }

    public function resolveRelationshipQueue(Project $project, array $instanceMapByCode)
    {
        $slugger = new AsciiSlugger();
        foreach ($this->relationshipQueue as $leftCoreCore => $queuedRelations) {
            //            assert(false, $this->relationshipQueue, $leftCoreCore, $queuedRelations);

            $leftCore = $this->appService->getCore($leftCoreCore);
            $leftProjectCore = $this->getProjectCore($project, $leftCore);

            foreach ($queuedRelations as $rightCoreCode => $rightRelations) {
                $core = $this->appService->getCore($rightCoreCode);
                $rightProjectCore = $this->getProjectCore($project, $core);

                // load/create the referenced instances.
                $codes = array_reduce(array_keys($rightRelations), function ($carry, string $codeString) use ($rightProjectCore, $slugger) {
                    if (u($codeString)->startsWith('@')) {
                        array_push($carry, trim($codeString, '@'));
                    } else {
                        if ($this->importSettings->autocreateFromLabel) {
                            $code = $slugger->slug($codeString);
                            $rightInstance = $this->getInstanceFromObj($rightProjectCore, [
                                'code' => $code,
                            ], [
                                'label' => $codeString,
                            ]);
                        //                            $instanceMap[$code] = $rightInstance;
                        } else {
                            throw new \Exception("$codeString is not in database, and autoCreateFromLabels is not set.");
                        }
                    }
                    return $carry;
                }, []);
                // get the existing ones from the map passed in.

                assert(count($instanceMapByCode));
                // otherwise, use this
                //                $referencedInstances =
                //                    $this->entityManager->getRepository(Instance::class)->findBy([
                //                        'projectCore' => $leftProjectCore,
                //                        'code' => $codes]);
                // get the new codes and create those.

                //                $instanceMap = [];
                //                foreach ($referencedInstances as $referencedInstance) {
                //                    $instanceMap[$referencedInstance->getCode()] = $referencedInstance;
                //                }
                $instanceMap = $instanceMapByCode[$rightCoreCode];

                // now add the codes that don't already exist.
                foreach ($codes as $code) {
                    if (! array_key_exists($code, $instanceMap)) {
                        if ($this->importSettings->autocreateFromCodes) {
                            $instance = $this->getInstanceFromObj($rightProjectCore, null, [
                                'code' => $code,
                            ]);
                            $instanceMap[$code] = $instance;
                        }
                    }
                }

                //            assert(false, $codes, count($codes), count($referencedInstances), $rightCoreCode, $instanceMap);
                //            $this->relationshipQueue[$rightInstanceCode][$relationship][] = $instance->getId();
                // now that the instanceMap is loaded, we can actually create the relations.
                //            assert(false, $rightRelations);
                foreach ($rightRelations as $instanceCode => $relations) {
                    $instanceCode = trim($instanceCode, '@');
                    $rightInstance = $instanceMap[$instanceCode];
                    assert(array_key_exists($instanceCode, $instanceMap), "Missing $instanceCode in " . join(',', array_keys($instanceMap)));

                    // shouldn't these already exist, in the config?
                    foreach ($relations as $type => $instances) {
                        $RelationField = $this->getExistingRelationField($leftProjectCore, $type);
                        foreach ($instances as $leftInstance) {
                            // sigh

                            $relation = $this->relationService->addRelation($leftInstance, $rightInstance, $RelationField);
                        }
                    }

                    //                    assert(false, $RelationField, $result);
                    //                    $this->addTranslations($relationshipType, $RelationField);
                    // we probably want to handle these label translations differently,
                }
            }
        }

        $this->entityManager->flush(); // hack, shouldn't be here.
    }

    /**
     * @param mixed $rows
     * @param mixed $row
     * @param User $user
     * @param array $translatedData
     * @return array|void
     */
    public function loadCachableData(Project $project, Core $core, iterable $rows, string $locale)
    {
        /** @var array<string, FieldInterface> $fieldsByCode */
        $fieldsByCode = [];
        // get all the keys we're going to need for translations
        foreach ($core->getFields() as $field) {
            $fieldsByCode[$field->getCode()] = $field;
        }
//        $codes = array_reduce($rows, function ($carry, array $row) use ($fieldsByCode) {});
        $codes = [];
        foreach ($rows as $row) {
            foreach ($row as $var => $val) {
                if (!array_key_exists($var, $fieldsByCode)) {
                    continue;
                }
                AppService::assertKeyExists($var, $fieldsByCode);
                $field = $fieldsByCode[$var];
                if ($field->isRelation()) {
                    $codes = array_merge($codes, $val);
                    assert(is_array($val));
                } elseif ($field->isCategory()) {
                    $codes[] = $val;
                }
            }
        }
        $codes = array_unique($codes);
        $codes = array_filter($codes, fn($x) => !is_numeric($x));
        // this loads the facet translations
//        assert($this->translationService, "@todo: refactor to avoid circular depedencies.");
        $translatedData = $this->translationService->getTranslationsByCodes($project->getProjectLocale(), $locale, $codes);

        return $translatedData;

    }

    // create the schema.org style schema, using LiForm
    public function createJsonSchema(Core $core, Schema $schema)
    {
        $project = $core->getProject();
        $formBuilder = $this->formFactory->createBuilder(FormType::class, options: [
            'csrf_protection' => false
        ]);

        foreach ($schema->getKeyedProperties() as $propertyName => $prop) {
            dd($propertyName, $prop);
        // the schema and the fields are tightly related.  Fields have permissions and display characteristics.

//        foreach ($config['outputSchema'] as $propertyName => $prop) {
            $propertyType = $prop['formType'];
            $options = [
                'required' => false
            ];
            if ($propertyType == CollectionType::class) {
                $options['allow_add'] = true;
            }

            if (count($prop)) {
                $options['attr'] = $prop;
//                        $columnType = json_encode($settings);
//                        $outputHeader .= ':' . $columnType;
//                $outputHeader .= '?' . http_build_query($settings);
//                        dd($outputHeader);
            }

            if ($settings['label'] ?? false) {
                $options['label'] = $settings['label'];
                unset($settings['label']);
            }

            $formBuilder->add($propertyName, $propertyType, $options);

        }
        $form = $formBuilder->getForm();

        // https://github.com/swaggest/php-json-schema -- can we import with this?
        // should validate wth https://github.com/opis/json-schema
        $schema = $this->liform->transform($form);


//        foreach ($schema['properties'] as $code => $property) {
//            dump($code, $property);
//        }
//        dd($property, $schema);

        assert($schema);
        $core->setSchema($schema);
        $schemaFilename = sprintf("%s-%s.json", $project->getCode(), $core->getCoreCode());
//        file_put_contents($schemaFilename, $schemaJson = json_encode($schema, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES));
        return $schemaFilename;


    }

    /**
     * @param mixed $tableName
     */
    public function getCore(string $tableName, ?Inst $inst=null): Core
    {
        assert(false, "get from pixieservice");
        // switch the database
        $this->pixieService->getConfig($owner->pixieCode);
        $coreRepository = $this->entityManager->getRepository(Core::class);
        $ownerCode = $owner?->code;
        // this doesn't pass the smell test.
        if ( empty($owner) || empty($this->cores[$owner->code])) {
            foreach ($coreRepository->findAll() as $core) {
//                assert($core->getOwner(), "Missing owner in core");
//                if ($core->getOwner() !== $owner) {
//                    dd($core->getOwner(), $owner);
//                }
//                assert($core->getOwner() === $owner);
                $this->cores[$core->code] = $core;
            }
        }

//        if (!$core = $this->coreRepository->find($tableName)) {
//        if (!$core = $this->cores[$owner->code][$tableName]??null) {
        if (!$core = $coreRepository->findOneBy(['code' => $tableName])) {
            $core = new Core($tableName, $owner);
//            foreach ($this->coreRepository->findAll() as $existingCore) {
//                dump($existingCore->get   Code(), $existingCore);
//            }
            assert($owner, "owner required when creating core");
            $this->pixieEntityManager->persist($core);
            assert($this->pixieEntityManager->contains($core));
            $this->cores[$ownerCode][$tableName] = $core;
//            dump($tableName, array_keys($this->cores));
        }
        if (!$this->pixieEntityManager->contains($core)) {
            dd($core, $this->cores);
        }
        dd($core, $owner);
//        dd($this->serializer->serialize($core, 'json', ['groups' => 'core.read']));
        return $core;

    }

}
