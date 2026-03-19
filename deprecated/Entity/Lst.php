<?php

// Lst php/Entity.php.twig

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use App\Annotations\CAField;
use App\Annotations\CATable;
use App\Entity\IdInterface;
use App\Entity\ImportDataInterface;
use App\Entity\ListItemInterface;
use App\Entity\ProjectInterface;
use App\Entity\UuidAttributeInterface;
use App\Repository\LstRepository;
use App\Traits\CollectiveAccessTrait;
use Survos\PixieBundle\Traits\IdTrait;
use Survos\PixieBundle\Traits\ImportDataTrait;
use App\Traits\InstanceTrait;
use App\Traits\NestedEntityTrait;
use App\Traits\ProjectCoreTrait;
use App\Traits\ProjectTrait;
use App\Traits\UuidAttributeTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Tree\Traits\NestedSetEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

// this is needed for the trait, not sure why, but it fails without it.

// #[ApiResource(operations: [new Get(), new Put(), new Delete(), new Patch(), new Post(uriTemplate: 'lsts'), new GetCollection()], shortName: 'lsts', denormalizationContext: ['groups' => ['write']], normalizationContext: ['groups' => ['read', 'tree']])]
#[Gedmo\Tree(type: 'nested')]
// DEPRECATED — #[ORM\Entity(repositoryClass: LstRepository::class)]
#[ORM\Table]
#[ORM\Index(name: 'lst_parent', fields: ['parent'])]
//#[ORM\UniqueConstraint(name: 'lsts_project_plus_code', columns: ['code'])]
//#[UniqueEntity(fields: ['project', 'code'])]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'id' => 'exact',
    'project' => 'exact',
])]
class Lst
//    implements
//    IdInterface, ProjectInterface, ListItemInterface, ImportDataInterface, InstanceInterface, UuidAttributeInterface
{
//    use IdTrait;
//    use UuidAttributeTrait;
//    use CollectiveAccessTrait;
//    use NestedEntityTrait;
//    use ProjectCoreTrait;
//    use ImportDataTrait;
//    use ProjectTrait;
//    use InstanceTrait;
//    use NestedSetEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    final public const API_SHORTNAME = 'lsts';

//    #[Groups(['write'])]
//    #[ACConfig(coreClass: Project::class)]
//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'lsts')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    #[ORM\Embedded(class:KeyValue::class, columnPrefix: 'import_')]
    private ?KeyValue $importDataWrapper = null;

    public function __construct()
    {
//        parent::__construct();
        $this->children = new ArrayCollection();
        $this->importDataWrapper = null;
        //  = new KeyValue(); ?
    }

    #[CAField('list_id', description: 'Unique numeric identifier used by CollectiveAccess internally to identify this list', caType: 0)]
    #[ORM\Column(name: 'list_id', type: 'integer', nullable: true, options: [
        'comment' => 'CollectiveAccess id',
    ])]
    private ?int $listId = null;

    /**
     * Doctrine(string) **caType(1)
     * *dbType(string)
     * *sysList() **default(null)
     */
    #[CAField('list_code', description: 'Unique code for list; used to identify the list for configuration purposes.', caType: 1)]
    #[ORM\Column(name: 'list_code', nullable: true, type: 'string', length: 255, options: [
        'comment' => 'List code',
    ])]
    private ?string $listCode = null;

    /**
     * Doctrine(int) **caType(0)
     * *dbType(integer)
     * *sysList() **default(null)
     */
    #[CAField('default_sort', description: 'Specifies the default method to employ to order items in this list.', caType: 0)]
    #[ORM\Column(name: 'default_sort', nullable: true, type: 'integer', options: [
        'comment' => 'Default sort order',
    ])]
    private ?int $defaultSort = null;

    /**
     * Doctrine(bool) **caType(7)
     * *dbType(boolean)
     * *sysList() **default(null)
     */
    #[CAField('is_system_list', description: 'Set this if the list is a list used by the system to populate a specific field (as opposed to a user defined list or vocabulary). In general, system lists are defined by the system installer - you should not have to create system lists on your own.', caType: 7)]
    #[ORM\Column(name: 'is_system_list', nullable: true, type: 'boolean', options: [
        'comment' => 'Is system list',
    ])]
    private ?bool $isSystemList = true;

    /**
     * Doctrine(bool) **caType(7)
     * *dbType(boolean)
     * *sysList() **default(null)
     */
    #[CAField('is_hierarchical', description: 'Set this if the list is hierarchically structured; leave unset if you are creating a simple &quot;flat&quot; list.', caType: 7)]
    #[ORM\Column(name: 'is_hierarchical', nullable: true, type: 'boolean', options: [
        'comment' => 'Is hierarchical',
    ])]
    private ?bool $isHierarchical = true;

    /**
     * Doctrine(bool) **caType(7)
     * *dbType(boolean)
     * *sysList() **default(null)
     */
    #[CAField('use_as_vocabulary', description: 'Set this if the list is to be used as a controlled vocabulary for cataloguing.', caType: 7)]
    #[ORM\Column(name: 'use_as_vocabulary', nullable: true, type: 'boolean', options: [
        'comment' => 'Use as vocabulary',
    ])]
    private ?bool $useAsVocabulary = true;

    #[Assert\Valid]
    #[Groups(['write'])]
    #[Gedmo\TreeParent]
    #[ORM\ManyToOne(targetEntity: 'Lst', inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'ancestor_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private $parent;

    #[Groups(['tree', 'read'])]
    public function getParentId(): ?Uuid
    {
        return $this->getParent() ? $this->getParent()->getId() : null;
    }

    #[ORM\OneToMany(targetEntity: 'Lst', mappedBy: 'parent')]
    #[ORM\OrderBy([
        'left' => 'ASC',
    ])]
    private $children;

    public function getImportDataWrapper(): KeyValue
    {
        return $this->importDataWrapper;
    }

    public function setImportDataWrapper(KeyValue $importDataWrapper): self
    {
        $this->importDataWrapper = $importDataWrapper;
        return $this;
    }

    public function getListId(): ?int
    {
        return $this->listId;
    }

    public function setListId(?int $listId): self
    {
        $this->listId = $listId;
        return $this;
    }

    public function getListCode(): ?string
    {
        return $this->listCode;
    }

    public function setListCode(?string $listCode): self
    {
        $this->listCode = $listCode;
        return $this;
    }

    public function getDefaultSort(): ?int
    {
        return $this->defaultSort;
    }

    public function setDefaultSort(?int $defaultSort): self
    {
        $this->defaultSort = $defaultSort;
        return $this;
    }

    public function getIsSystemList(): ?bool
    {
        return $this->isSystemList;
    }

    public function setIsSystemList(?bool $isSystemList): self
    {
        $this->isSystemList = $isSystemList;
        return $this;
    }

    public function getIsHierarchical(): ?bool
    {
        return $this->isHierarchical;
    }

    public function setIsHierarchical(?bool $isHierarchical): self
    {
        $this->isHierarchical = $isHierarchical;
        return $this;
    }

    public function getUseAsVocabulary(): ?bool
    {
        return $this->useAsVocabulary;
    }

    public function setUseAsVocabulary(?bool $useAsVocabulary): self
    {
        $this->useAsVocabulary = $useAsVocabulary;
        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection|Lst[]
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(Lst $child): self
    {
        if (! $this->children->contains($child)) {
            $this->children[] = $child;
            $child->setParent($this);
        }
        return $this;
    }

    public function removeChild(Lst $child): self
    {
        if ($this->children->removeElement($child)) {
            // set the owning side to null (unless already changed)
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }
}
