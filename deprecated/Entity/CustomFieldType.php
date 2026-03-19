<?php

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use App\Entity\ListItemInterface;
use App\Entity\NestedEntityInterface;
use App\Repository\CustomFieldTypeRepository;
use App\Traits\CollectiveAccessTrait;
use App\Traits\InstanceTrait;
use App\Traits\NestedEntityTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Id\UuidGenerator;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Tree\Traits\NestedSetEntity;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\PixieBundle\Traits\IdTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// #[ApiResource(denormalizationContext: ['groups' => ['write']], normalizationContext: ['groups' => ['read']])]
// DEPRECATED — #[ORM\Entity(repositoryClass: CustomFieldTypeRepository::class)]
//#[Gedmo\Tree(type: 'nested')]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'project' => 'exact',
])]
class CustomFieldType #@ implements RouteParametersInterface, \Stringable
{
    use IdTrait;
//    use InstanceTrait;
//    use NestedEntityTrait;
//    use NestedSetEntity;
    use RouteParametersTrait;
    //    use ProjectTrait;
//    use CollectiveAccessTrait;

    #[Groups(['spreadsheet'])]
    #[ORM\Column(type: 'string', length: 64)]
    private ?string $name = null;

    //    #[Groups(['spreadsheet'])]
    //    #[ORM\ManyToOne(targetEntity: FieldType::class, inversedBy: 'customFieldTypes')]
    //    #[ORM\JoinColumn(nullable: false, referencedColumnName: 'code')]
    //    private ?FieldType $fieldType = null;
//    #[Groups(['write'])]
//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'customFieldTypes')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    #[ORM\OneToMany(targetEntity: CustomField::class, mappedBy: 'customFieldType', orphanRemoval: true, cascade: ['persist'])]
    private $customFields;

    public function __construct(?Project $project = null, ?string $code=null)
    {
        if ($project) {
            $project->addCustomFieldType($this);
        }
        $this->initId($code);
        $this->customFields = new ArrayCollection();
        $this->children = new ArrayCollection();
    }

    #[Assert\Valid]
    #[Groups(['write'])]
//    #[Gedmo\TreeParent]
    #[ORM\ManyToOne(targetEntity: CustomFieldType::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'ancestor_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private $parent;

    #[ORM\OneToMany(targetEntity: CustomFieldType::class, mappedBy: 'parent')]
    #[ORM\OrderBy([
        'left' => 'ASC',
    ])]
    private $children;

    #[ORM\ManyToOne(targetEntity: AttributeType::class, inversedBy: 'customFieldTypes')]
    private $attributeType;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return Collection|CustomField[]
     */
    public function getCustomFields(): Collection
    {
        return $this->customFields;
    }

    public function addCustomField(CustomField $customField): self
    {
        if (! $this->customFields->contains($customField)) {
            $this->customFields[] = $customField;
            $customField->setCustomFieldType($this);
        }
        return $this;
    }

    public function removeCustomField(CustomField $customField): self
    {
        if ($this->customFields->removeElement($customField)) {
            // set the owning side to null (unless already changed)
            if ($customField->getCustomFieldType() === $this) {
                $customField->setCustomFieldType(null);
            }
        }
        return $this;
    }

    public function getAttributeType(): ?AttributeType
    {
        return $this->attributeType;
    }

    public function setAttributeType(?AttributeType $attributeType): self
    {
        $this->attributeType = $attributeType;
        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?NestedEntityInterface $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection|CustomFieldType[]
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(CustomFieldType $child): self
    {
        if (! $this->children->contains($child)) {
            $this->children[] = $child;
            $child->setParent($this);
        }
        return $this;
    }

    public function removeChild(CustomFieldType $child): self
    {
        if ($this->children->removeElement($child)) {
            // set the owning side to null (unless already changed)
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->getCode();
    }

    public function getUniqueIdentifiers(): array
    {
        return $this->getProject()->getrp(['customFieldTypeId' => $this->getCode()]);
        // TODO: Implement getUniqueIdentifiers() method.
    }


}
