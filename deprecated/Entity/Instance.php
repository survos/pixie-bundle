<?php

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\IdInterface;
use App\Entity\ImportDataInterface;
use App\Entity\TranslatableFieldsProxyInterface;
use App\Entity\UuidAttributeInterface;
use App\Filters\UriFacetFilter;
use App\Traits\InstanceTrait;
use App\Traits\NestedEntityTrait;
use App\Traits\TranslatableFieldsProxyTrait;
use App\Traits\UuidAttributeTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Tree\Traits\NestedSetEntity;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\PixieBundle\Entity\Field\CategoryField;
use Survos\PixieBundle\Entity\Field\Field;
use Survos\PixieBundle\Entity\Field\FieldInterface;
use Survos\PixieBundle\Entity\Field\RelationField;
use Survos\PixieBundle\Entity\Relation as Relation;
use Survos\PixieBundle\Traits\CoreIdTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use function Symfony\Component\String\u;
use Gedmo\Mapping\Annotation as Gedmo;

//use App\Traits\InstanceJsonTrait;

//

// DEPRECATED — #[ORM\Entity(repositoryClass: InstanceRepository::class)]
//#[ORM\Index(name: "instance_project", columns: ['project_id'])]
#[ORM\Index(name: "instance_core", columns: ['core_id'])]
//#[ORM\Index(name: "rename_notification_idx", columns: ["rename_notification"], options: ["where" => "rename_notification = TRUE"])]

//#[ApiResource(
//    operations: [new Get(),
//        new GetCollection(
//            name: 'core',
//            uriTemplate: "doctrine/{coreId}",
//            uriVariables: ['core'],
//        )],
//
//    # @todo: secure new Put(), new Delete(), new Patch(), new Post(),
//    denormalizationContext: [
//        'groups' => ['instance.write', 'tree'],
//    ],
//    normalizationContext: [
//        'groups' => ['instance.read', 'tree', 'rp'],
//    ]
//)]
//#[GetCollection(
//    uriTemplate: "meili/{ownerId}/{projectId}/{coreId}/{indexName}",
//    uriVariables: ["ownerId", "projectId", "coreId","indexName"],
////    requirements: ['core' => '\d+'],
//    provider: MeiliSearchStateProvider::class,
//    denormalizationContext: [
//        'groups' => ['instance.write', 'tree'],
//    ],
//    normalizationContext: [
//        'groups' => ['instance.read', 'tree', 'rp'],
//    ]
//)]
//#[ApiFilter(OrderFilter::class, properties: ['name', 'code'], arguments: [
//    'orderParameterName' => 'order',
//])]
//#[ApiFilter(UriFacetFilter::class, properties: ['coreId'], arguments: ['searchParameterName' => 'coreId'])]
//// can we move this to a property
//#[ApiFilter(JsonSearchFilter::class, properties: ['attributes'], arguments: ['searchParameterName' => 'attribute_search'])]
//#[ApiFilter(SearchFilter::class, properties: [
//    'project' => 'exact',
//    'code' => 'exact',
//    'label' => 'partial',
//    'description' => 'partial',
//    'referenceCount' => 'exact',
//    'core' => 'exact',
//])]
//#[Gedmo\Tree(type: 'nested')]
class Instance implements
//    IdInterface,
//    TranslatableFieldsProxyInterface,
    CoreInterface, // constants

//    AsBarcodeInterface,
//    Translatable,
    RouteParametersInterface,
//    ImportDataInterface,
//    InstanceInterface,
//    UuidAttributeInterface,
    \Stringable
{
//    use UuidAttributeTrait;
//    use CollectiveAccessTrait;
//    use ImportDataTrait;
//    use InstanceTrait;
//    use TranslatableFieldsProxyTrait;
    use CoreIdTrait;

//    use NestedEntityTrait;
//    use NestedSetEntity;
//    use RouteParametersTrait;

    //    #[ORM\Id]
    //    #[ORM\GeneratedValue]
    //    #[ORM\Column]
    //    private ?int $id = null;
    //
    #[ORM\ManyToOne(cascade: ['persist'], inversedBy: 'instances')]
    #[ORM\JoinColumn(nullable: false)]
    #[ApiFilter(SearchFilter::class, strategy: 'exact')]
//    #[ApiProperty(identifier: true)]
    protected Core $core;

    //
//    #[ORM\Embedded(class: "KeyValue", columnPrefix: "import_")]
//    private ?KeyValue $importDataWrapper = null;

    public const DB_CODE_FIELD = 'code';
    public const DB_LABEL_FIELD = 'label';
    public const DB_NAME_FIELD = 'name'; // aka title?
    public const DB_DESCRIPTION_FIELD = 'description';

    public const TRANSLATABLE_FIELDS = [self::DB_LABEL_FIELD, self::DB_NAME_FIELD, self::DB_DESCRIPTION_FIELD];
    public const DB_FIELDS = [self::DB_CODE_FIELD, ...self::TRANSLATABLE_FIELDS];

    private ?string $name;

    public static function loadMetadata(ORM\ClassMetadata $metadata)
    {
        dd($metadata);
        $builder = new ORM\Builder\ClassMetadataBuilder($metadata);
        $builder->createField('id', 'integer')->makePrimaryKey()->generatedValue()->build();
        $builder->createField('xyz', 'integer')->build();
        $builder->addField('name', 'string');
    }

    public function setName($x)
    {
        $this->name = $x;
    }

//    public function __call($method, $arguments)
//    {
//        return $this->proxyCurrentLocaleTranslation($method, $arguments);
//    }


    public function __construct(?Core $core = null, ?string $code = null, ?string $id = null)
    {
        if ($core && !$id) {
            $id = $core->createInstanceId($code);
        }
        $this->initId(id: $id, code: $code);
        if ($core) {
            $core->addInstance($this);
//            $this->setLocale($core->getProject()->getLocale());
//            $this->setTranslatableLocale($projectCore->getProject()->getProjectLocale());
            assert($core->getInstanceCount());
        }
        assert(isset($this->id), " id is not set during __construct");
        assert($this->getId(), "missing uuid after construct");
//        $this->children = new ArrayCollection();
//        $this->importDataWrapper = null; //  = new KeyValue(); ?
        $this->leftRelations = new ArrayCollection();
        $this->rightRelations = new ArrayCollection();
        $this->instanceCategories = new ArrayCollection();
        $this->refs = new ArrayCollection();
        $this->relations = new ArrayCollection();
    }

//    #[Gedmo\TreeParent]
//    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: "children")]
//    #[ORM\JoinColumn(name: "ancestor_id", referencedColumnName: "id", onDelete: "CASCADE")]
//    #[Assert\Valid()]
//    #[Groups(['instance.write'])]
//    private $parent;

//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'instances')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
//    #[Groups(['instance.write'])]
//    #[ACConfig(coreClass: Project::class)]
//    protected Project $project;

    #[Groups(['instance.tree', 'instance.read'])]
    public function getParentId(): ?Uuid
    {
        return $this->getParent() ? $this->getParent()->getId() : null;
    }

//    #[ORM\OneToMany(targetEntity: self::class, mappedBy: "parent")]
//    #[ORM\OrderBy([
//        'left' => 'ASC',
//    ])]
//    private $children;

    #[ORM\OneToMany(mappedBy: 'leftInstance', targetEntity: Relation::class, orphanRemoval: true)]
    private Collection $leftRelations;

    #[ORM\OneToMany(mappedBy: 'rightInstance', targetEntity: Relation::class, orphanRemoval: true)]
    private Collection $rightRelations;

    #[ORM\OneToMany(mappedBy: 'instance', targetEntity: InstanceCategory::class, orphanRemoval: true, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collection $instanceCategories;

    #[ORM\OneToMany(mappedBy: 'instance', targetEntity: Reference::class, orphanRemoval: true, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collection $refs;

    // we should have the RelationField, for grouping!

    #[ORM\OneToMany(mappedBy: 'instance', targetEntity: Relation::class, orphanRemoval: true, cascade: ['persist'])]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Collection $relations;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $barcodeStrings = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['instance.read'])]
    private ?string $wikiCode = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['instance.read'])]
    private ?array $tags = [];

    #[Groups(['instance.tree', 'instance.read'])]
    public function getUniqueIdentifiers(): array
    {
        return $this->getCore()->getrp([
            'instanceId' => $this->getCode() ?: $this->getId(),
        ]);
    }

    /**
     * @return Collection<int, Relation>
     */
    public function getLeftRelations(): Collection
    {
        return $this->leftRelations;
    }

    public function getRelationsGroup(): array
    {
        $group = [];
        foreach ($this->getLeftRelations() as $relation) {
            $rt = $relation->getRelationField();
            $group[$rt->getCode()][$rt->getRightCoreCode()][] = $relation->getRightInstance();
        }
        foreach ($this->getRightRelations() as $relation) {
            $rt = $relation->getRelationField();
            $group[$rt->getReverseCode()][$rt->getLeftCoreCode()][] = $relation->getLeftInstance();
        }
//        dd($group);
        return $group;
    }

    public function addLeftRelation(Relation $leftRelation): self
    {
        if (!$this->leftRelations->contains($leftRelation)) {
            $this->leftRelations->add($leftRelation);
            $leftRelation->setLeftInstance($this);
        }

        return $this;
    }

    public function removeLeftRelation(Relation $leftRelation): self
    {
        if ($this->leftRelations->removeElement($leftRelation)) {
            // set the owning side to null (unless already changed)
            if ($leftRelation->getLeftInstance() === $this) {
                $leftRelation->setLeftInstance(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Relation>
     */
    public function getRightRelations(): Collection
    {
        return $this->rightRelations;
    }

    public function addRightRelation(Relation $rightRelation): self
    {
        if (!$this->rightRelations->contains($rightRelation)) {
            $this->rightRelations->add($rightRelation);
            $rightRelation->setRightInstance($this);
        }

        return $this;
    }

    public function removeRightRelation(Relation $rightRelation): self
    {
        if ($this->rightRelations->removeElement($rightRelation)) {
            // set the owning side to null (unless already changed)
            if ($rightRelation->getRightInstance() === $this) {
                $rightRelation->setRightInstance(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, InstanceCategory>
     */
    #[Groups(['instance.tree', 'instance.read'])]
    public function getInstanceCategories(): Collection
    {
        return $this->instanceCategories;
    }

    public function getCategoriesByCategoryType(CategoryField $categoryField): Collection
    {
        //        return $this->getCategoryByCategoryTypeCode()
        // candidate for cache, keyed, etc.  We may even already it it somewhere.
        return $this->getInstanceCategories()->filter(fn(InstanceCategory $instanceCategory) => $categoryField == $instanceCategory->getCategory()->getCategoryField());
    }

    private function getRelationsByField(RelationField|FieldInterface $relationField)
    {
        return $this->getRelations()->filter(fn(Relation $relation) => $relation->getRelationField() == $relationField);
    }


    public function getRelationInstances(Collection $relations): array
    {
        $instances = [];
        /** @var Relation $relation */
        foreach ($relations as $relation) {
            $instances[] = $relation->getRightInstance();
        }
        return $instances;

    }

    public function getFieldValue(Field $field): mixed
    {
        $code = $field->getCode();
        // why type and not field?
        $value = match ($field->getType()) {
            $field::TYPE_RELATION => $this->getRelationsByField($field),
            $field::TYPE_ATTRIBUTE => $this->get($code),
            $field::TYPE_CATGORY => $this->getInstanceCategories()[0],
            $field::TYPE_INTRINSIC => $this->{'get' . $field->getInternalCode()}(),
            default => assert(false, "Invalid type: " . $field->getType())
        };
        return $value;
//        dd($field, $field->getType(), $value, $this->getRelations());
    }

    public function getInstancesByCategoryTypeCode(): array
    {
        $x = [];
        foreach ($this->getInstanceCategories() as $instanceCategory) {
            $x[$instanceCategory->getCategory()->getCategoryTypeCode()] = $instanceCategory->getInstance(); // the instance, e.g. second_floot
        }
        return $x;
        // candidate for cache, keyed, etc.  We may even already it it somewhere.
    }

    /**
     * @return <string,Category[]>
     */
    public function getCategoriesGroupedByCategoryTypeCode(): array
    {
        // cache!!
        $x = [];
        foreach ($this->getInstanceCategories() as $instanceCategory) {
            $x[$instanceCategory->getCategory()->getCategoryTypeCode()] = $instanceCategory->getCategory(); // the instance, e.g. second_floot
        }
        return $x;
        // candidate for cache, keyed, etc.  We may even already have it somewhere.
    }

    /**
     * @return array<Instance[]>
     */
    public function getRelationInstancesByRelationField(RelationField $RelationField): Collection
    {
        return $this->getRelations()
            ->filter(fn(Relation $relation) => $relation->getRelationField() === $RelationField)
            ->map(fn(Relation $relation) => $relation->getRightInstance());
    }

    /**
     * @return Relation
     */
    public function getRelationsGroupedByType(?bool $renderSingularOnly = null): array
    {
        $relationByTypeCode = [];
        foreach ($this->getRelations() as $relation) {
            $rt = $relation->getRelationField();
            if (is_null($renderSingularOnly) || ($renderSingularOnly !== $rt->isRenderLabelsAsRelation())) {
                continue;
            }
            if (!array_key_exists($rt->getCode(), $relationByTypeCode)) {
                $relationByTypeCode[$rt->getCode()] = [];
            }
            $relationByTypeCode[$rt->getCode()][] = $relation;
        }
        return $relationByTypeCode;
    }

    public function getCategoryByCategoryTypeCode($categoryTypeCode): ?Category
    {
        $ic = $this->getInstanceCategories()
            ->filter(
                fn(InstanceCategory $instanceCategory) => $instanceCategory->getCategoryTypeCode() == $categoryTypeCode
            )->first();
        return $ic ? $ic->getCategory() : null;
    }

    public function setCategoryByCategoryTypeCode(string $categoryTypeCode, ?Category $category): self
    {
        return $this->addCategory($category);
        //        return $this->getCategoriesGroupedByCategoryTypeCode()[$categoryTypeCode]->setCategory($category);
    }

    public function addCategory(Category $category): self
    {
        /** @var InstanceCategory $instanceCategory */
        if (!$instanceCategory = $this->getInstanceCategories()->filter(fn(InstanceCategory $instanceCategory) => $instanceCategory->getCategoryTypeCode() == $category->getCategoryTypeCode())->first()) {
            $this->addInstanceCategory(new InstanceCategory($this, $category));
        } else {
            $instanceCategory->setCategory($category);
        }
        //        foreach ($this->getInstanceCategories() as $instanceCategory) {
        //            // we already have one of this category, change it if it's not the same
        //            if ($instanceCategory->getCategoryTypeCode() == $category->getCategoryTypeCode()) {
        //                if ($instanceCategory->getCategory() <> $category) {
        //                    $instanceCategory->setCategory($category);
        ////                    $this->removeInstanceCategory($instanceCategory);
        //                }
        //            }
        //        }
        return $this;
    }

    public function addInstanceCategory(InstanceCategory $instanceCategory): self
    {
        if (!$this->instanceCategories->contains($instanceCategory)) {
            $this->instanceCategories->add($instanceCategory);
            $instanceCategory->setInstance($this);
        }

        return $this;
    }

    public function removeInstanceCategory(InstanceCategory $instanceCategory): self
    {
        if ($this->instanceCategories->removeElement($instanceCategory)) {
            // set the owning side to null (unless already changed)
            if ($instanceCategory->getInstance() === $this) {
                $instanceCategory->setInstance(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Reference>
     */
    #[Groups(['instance.read'])]
    public function getRefs(): Collection
    {
        return $this->refs;
    }

    // why is symfony saying reference is a reserved word??

    /**
     * @return Collection|Reference[]
     */
    public function getReferences(): Collection
    {
        return $this->getRefs();
    }

    public function getReferenceByS3(string $s3Filename): ?Reference
    {
        return $this->getReferences()->filter(fn(Reference $reference) => $s3Filename === $reference->getS3Path())->first() ?: null;
    }

    public function getReferenceByLocalFilename(string $localFilename): ?Reference
    {
        return $this->getReferences()->filter(fn(Reference $reference) => $localFilename === $reference->getLocalFilename())->first() ?: null;
    }

    #[Groups('instance.read')]
    public function getReferenceCount(): ?int
    {
        return $this->getReferences()->count();
    }

    public function addReference(Reference $reference): self
    {
        if (!$this->refs->contains($reference)) {
            $this->refs->add($reference);
            $reference->setInstance($this);
        }

        return $this;
    }

    public function removeReference(Reference $reference): self
    {
        if ($this->refs->removeElement($reference)) {
            // set the owning side to null (unless already changed)
            if ($reference->getInstance() === $this) {
                $reference->setInstance(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Relation>
     */
    public function getRelations(): Collection
    {
        return $this->relations;
    }

    public function addRelation(Relation $relation): self
    {
        if (!$this->relations->contains($relation)) {
            $this->relations->add($relation);
            $relation->setInstance($this);
            $this->incRelationCount();
            $this->getCore()->addRelation($relation);
        }

        return $this;
    }

    public function removeRelation(Relation $relation): self
    {
        if ($this->relations->removeElement($relation)) {
            // set the owning side to null (unless already changed)
            if ($relation->getInstance() === $this) {
                $relation->setInstance(null);
            }
        }

        return $this;
    }

    public function getBarcodeStrings(): ?string
    {
        return $this->barcodeStrings;
    }

    public function setBarcodeStrings(?string $barcodeStrings): self
    {
        $this->barcodeStrings = $barcodeStrings;

        return $this;
    }

    public function asBarcodeString(bool $includeId = false): string
    {
        $data = [$this->getCoreCodeFromSheetName(), 'i', $this->getCode()];
        if ($includeId) {
            $data[] = $this->getId()->toBase58();
        }
        return join(',', $data);
    }

    public function relationBarcodeString(RelationField $RelationField)
    {
        return join(',', [$RelationField->getLeftCore()->getCoreCode(), 'rc', $RelationField->getCode(), $this->getCode()]);
    }

    public function __toString(): string
    {
        return trim(sprintf("%s (%s)", $this->getLabel(), $this->getCode()));
    }

    public function getWikiCode(): ?string
    {
        return $this->wikiCode;
    }

    public function setWikiCode(?string $wikiCode): self
    {
        $this->wikiCode = $wikiCode;

        return $this;
    }

    public function getDisplay(): string
    {
        return $this->getLabel() ?: '@' . $this->getCode();
    }

    // hack
    public function getOcrByPage(): array
    {
        $pages = [];
        if (!$ocr = $this->get('ocr', false)) {
            return [];
        }
//        dd($ocr);
        foreach ($ocr as $lang => $ocrText) {
            $hasOcr = preg_match_all('|<div id="(\d+)" lang="(.*?)">(.*?)</div>|sm', $ocrText, $mm, PREG_SET_ORDER);
            foreach ($mm as $m) {
                [$unused, $pageNumber, $pageLang, $text] = $m;
                $pages[$pageNumber][$lang] = $text;
            }
        }
        return $pages;
    }

    public function getTags(): array
    {
        return $this->tags ?? [];
    }

    public function setTags(?array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function addTag(string $tag)
    {
        if (!in_array($tag, $this->getTags())) {
            $this->tags[] = $tag;
        }
    }

    static public function referenceCodesFromString(string $referenceSring): array
    {
        $referenceData = [];
        foreach (explode("\n", $referenceSring) as $idx => $ref) {
            if (str_contains($ref, '@')) {
                $ref = u($ref)->after('@')->toString(); // for penn, etc.
            }
            $referenceData[] = $ref;
        }
        return $referenceData;

    }

    public function getReferenceCodes(): array
    {
        return self::referenceCodesFromString($this->get('references', ''));
    }


}
