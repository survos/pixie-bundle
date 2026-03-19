<?php

// This replaces PlaceType, ObjType, ObjSource
//  Source, Type, Accession -- these could be ModuleConfigurationCategory (instead of type).
// could be CoreConfig, Structure/


namespace Survos\PixieBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Survos\PixieBundle\Traits\IdTrait;
use Survos\PixieBundle\Traits\ImportDataTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ReadableCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Tree\Traits\NestedSetEntity;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\PixieBundle\Entity\Field\CategoryField;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

// DEPRECATED — #[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'pc_category_code', columns: ['core_id', 'category_field_id', 'code'])]
#[UniqueEntity(['code', 'categoryField', 'projectCore'])]
//#[ApiResource(
//    operations: [new Get(), new Put(), new Delete(), new Patch(), new Post(), new GetCollection()],
//    shortName: 'categories',
//    denormalizationContext: [
//        'groups' => ['write', 'tree'],
//    ],
//    normalizationContext: [
//        'groups' => ['read', 'tree'],
//    ]
//)]
//#[Gedmo\Tree(type: 'nested')]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'id' => 'exact',
    'categoryTypeCode' => 'exact',
    'projectCore' => 'exact',
    'project' => 'exact',
])]
class Category
{
    use IdTrait;
    public const PLACE_NEW = 'new';


    // instances? settings? InstanceInterface?  Do the instances need a super-clsss? https://www.doctrine-project.org/projects/doctrine-orm/en/2.13/reference/inheritance-mapping.html
    // or maybe we do this as a query and not a bi-directional relationship.
    //    #[ORM\OneToMany(targetEntity: Place::class, mappedBy: 'placeType')]
    //    private $places;

    //    #[Groups(['write'])]
//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'categories')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    #[Groups(['write', 'config.read'])]
    #[ORM\ManyToOne(targetEntity: Core::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Core $core;

    #[Assert\Valid]
    #[Groups(['write'])]
//    #[Gedmo\TreeParent]
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(referencedColumnName: 'id', onDelete: 'CASCADE')]
    private $parent;

    #[Groups(['tree', 'read'])]
    public function getParentId(): ?Uuid
    {
        return $this->getParent() ? $this->getParent()->getId() : null;
    }

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy([
        'left' => 'ASC', // @gedmo traits missing?
    ])]
    private $children;

    //    #[ORM\Column(length: 12)]
    //    #[Groups(['write','read'])]
    //    private ?string $categoryTypeCode = null;

    #[ORM\ManyToOne(inversedBy: 'categories', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull()]
    #[Groups(['write', 'config.read'])]
    private CategoryField $categoryField;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: InstanceCategory::class, orphanRemoval: true)]
    private Collection $instanceCategories;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $requiredParent = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $importFields = null;

    public function __construct(
        ?CategoryField $categoryField = null,
        ?string        $code = null,
        ?string        $label = null
    )
    {

        $this->initId();
        assert($categoryField, "no field for $code");

        assert($label, "missing label for $code");
        $label = trim($label, '`');
        if ($code) {
            $slug = self::slug($code);
            $this->setCode($slug);
            // e.g. during import
            if ($slug <> $code) {
                $this->setLabel($code);
            }
        }

        if ($label) {
            $locale = $categoryField->getProjectLocale();
            $this->setLabel($label, $locale);
//            dd($label, $categoryField->getProject()->getProjectLocale(), $this->getLabel($categoryField->getProject()->getProjectLocale()));
        }
        //        $this->places = new ArrayCollection(); // ??
        $this->children = new ArrayCollection();
        //        $this->relations = new ArrayCollection();
        //        $this->references = new ArrayCollection();
        // API Platform does not pass params to the constructor
        if ($categoryField) {
            $categoryField->addCategory($this);
        }

        //        $categoryType->getProjectCore()->addCategory($this);
        $this->instanceCategories = new ArrayCollection();
    }

    // this is needed so that the look up doesn't fail for new categories, esp when accents change.  Probably needed everywhere.

    /** @deprecated "Use AppService:: */
    public static function slug($code): string
    {
        return AppService::slugify($code);
    }

    #[Groups(['read'])]
    public function getCategoryTypeCode(): ?string
    {
        return $this->getCategoryField()->getInternalCode();
    }

    /**
     * @return CategoryField
     */
    public function getCategoryField(): CategoryField
    {
        return $this->categoryField;
    }

    /**
     * @param CategoryField $categoryField
     * @return Category
     */
    public function setCategoryField(CategoryField $categoryField): Category
    {
        $this->categoryField = $categoryField;
        $this->core = $categoryField->getCore();
        $this->project = $categoryField->getProject();

        return $this;
    }


    public function isType(): bool
    {
        return $this->getCategoryField()->getInternalCode() === CategoryField::TYPE;
    }

    public function islist(): bool
    {
        return false; // 'list' === $this->getCategoryTypeCode();
    }

    public function isSource(): bool
    {
        return CategoryField::SOURCE === $this->getCategoryTypeCode();
    }

    #[Groups(['tree'])]
    public function getName(): string
    {
        return $this->__toString();
    }

    #[Groups(['tree'])]
    public function setName(string $name)
    {
        //        assert(false, "why...?"); because it's needed for NestedTree
        $this->code = $name;
    }

    public function __toString(): string
    {
        return $this->getCode();
    }

    public function getCategoryType(): CategoryField
    {
        return $this->getCategoryField();
    }

    public function getInstances(): ReadableCollection
    {
        return $this->getInstanceCategories()->map(fn(InstanceCategory $instanceCategory) => $instanceCategory->getInstance());
    }

    /**
     * @return Collection<int, InstanceCategory>
     */
    public function getInstanceCategories(): Collection
    {
        return $this->instanceCategories;
    }

    public function addInstanceCategory(InstanceCategory $instanceCategory): self
    {
        if (!$this->instanceCategories->contains($instanceCategory)) {
            $this->instanceCategories->add($instanceCategory);
            $instanceCategory->setCategory($this);
        }

        return $this;
    }

    public function removeInstanceCategory(InstanceCategory $instanceCategory): self
    {
        if ($this->instanceCategories->removeElement($instanceCategory)) {
            // set the owning side to null (unless already changed)
            if ($instanceCategory->getCategory() === $this) {
                $instanceCategory->setCategory(null);
            }
        }

        return $this;
    }

    public function getUniqueIdentifiers(): array
    {
        return $this->getCategoryField()->getRP([
            'categoryId' => $this->getCode(),
        ]);
    }

    public function getInstanceCount(): int
    {
        // @todo: cache as related table
        return $this->getInstanceCategories()->count();
    }

    public function asBarcodeString(bool $includeId = false): string
    {
        $data = [$this->getCoreCodeFromSheetName(), 'c', $this->getCategoryTypeCode(), $this->getCode()];
        if ($includeId) {
            $data[] = $this->getId()->toBase58();
        }
        return join(',', $data);
    }

    public function getRequiredParent(): ?string
    {
        return $this->requiredParent;
    }

    public function setRequiredParent(?string $requiredParent): self
    {
        $this->requiredParent = $requiredParent;

        return $this;
    }

    #[Groups(['spreadsheet', 'read'])]
    public function getParentCode(): ?string
    {
        return $this->getParent()?->getCode();
    }

    public function getImportFields(): ?string
    {
        return $this->importFields;
    }

    public function setImportFields(?string $importFields): self
    {
        $this->importFields = $importFields;

        return $this;
    }

//    public function setCode(string $code): self
//    {
//        assert(false, $code);
//    }
}
