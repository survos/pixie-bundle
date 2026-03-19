<?php

namespace Survos\PixieBundle\Entity;

/*
 * customFieldType is optional
 * tree structure/hierarchical
 * can have 0+ Fields
 *
 */

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use App\Repository\CustomFieldRepository;
use App\Traits\InstanceTrait;
use App\Traits\NestedEntityTrait;
use App\Traits\ProjectTrait;
use App\Traits\TranslatableFieldsProxyTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Tree\Traits\NestedSetEntity;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\PixieBundle\Entity\Field\Field;
use Survos\PixieBundle\Traits\IdTrait;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// #[ApiResource(denormalizationContext: ['groups' => ['write']], normalizationContext: ['groups' => ['read']])]
// DEPRECATED — #[ORM\Entity(repositoryClass: CustomFieldRepository::class)]
//#[Gedmo\Tree(type: 'nested')]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'project' => 'exact',
])]
class CustomField implements RouteParametersInterface
{
    use IdTrait;
    use RouteParametersTrait;
//    use CollectiveAccessTrait;

    #[ORM\ManyToOne(targetEntity: Core::class, inversedBy: 'customFields', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private Core $core;

//    #[Groups(['write'])]
//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'customFields')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    #[ORM\OneToMany(targetEntity: Field::class, mappedBy: 'customField')]
    protected $fields;

    #[ORM\ManyToOne(targetEntity: CustomFieldType::class, inversedBy: 'customFields', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?CustomFieldType $customFieldType = null;

    #[Assert\Valid]
    #[Groups(['write'])]
//    #[Gedmo\TreeParent]
    #[ORM\ManyToOne(targetEntity: CustomField::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'ancestor_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private $parent;

    #[ORM\OneToMany(targetEntity: CustomField::class, mappedBy: 'parent')]
    #[ORM\OrderBy([
        'left' => 'ASC',
    ])]
    private $children;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $orderIdx;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private $includeSubtypes;

    public function __construct(?Core $core = null, ?string $code=null)
    {
        $this->initId($code);
        if ($core) {
            $core->addCustomField($this);
        }
        $bt = debug_backtrace();
        $caller = array_shift($bt);
        $this->fields = new ArrayCollection();
        $this->children = new ArrayCollection();
    }

    public function getCore(): Core
    {
        return $this->core;
    }

    public function setCore(Core $core): self
    {
        $this->core = $core;
        return $this;
    }

    public function getCustomFieldType(): ?CustomFieldType
    {
        return $this->customFieldType;
    }

    public function setCustomFieldType(?CustomFieldType $customFieldType): self
    {
        $this->customFieldType = $customFieldType;
        return $this;
    }

    public function getOrderIdx(): ?int
    {
        return $this->orderIdx;
    }

    public function setOrderIdx(?int $orderIdx): self
    {
        $this->orderIdx = $orderIdx;
        return $this;
    }

    public function getIncludeSubtypes(): ?bool
    {
        return $this->includeSubtypes;
    }

    public function setIncludeSubtypes(?bool $includeSubtypes): self
    {
        $this->includeSubtypes = $includeSubtypes;
        return $this;
    }

    /**
     * @return Collection|Field[]
     */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(Field $field): self
    {
        if (! $this->fields->contains($field)) {
            $this->fields[] = $field;
            $field->setCustomField($this);
        }
        return $this;
    }

    public function removeField(Field $field): self
    {
        if ($this->fields->removeElement($field)) {
            // set the owning side to null (unless already changed)
            if ($field->getCustomField() === $this) {
                $field->setCustomField(null);
            }
        }
        return $this;
    }

    public function getName(): string
    {
        return $this->getCustomFieldType()->getName();
    }

    public function getType(): CustomFieldType
    {
        return $this->getCustomFieldType();
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
     * @return Collection|CustomField[]
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(CustomField $child): self
    {
        if (! $this->children->contains($child)) {
            $this->children[] = $child;
            $child->setParent($this);
        }
        return $this;
    }

    public function removeChild(CustomField $child): self
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
