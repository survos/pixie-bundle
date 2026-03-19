<?php

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use App\Entity\TranslatableFieldsProxyInterface;
use App\Entity\UuidAttributeInterface;
use App\Repository\FieldSetRepository;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\PixieBundle\Traits\IdTrait;
use App\Traits\ProjectCoreTrait;
use App\Traits\TranslatableFieldsProxyTrait;
use App\Traits\UuidAttributeTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\PixieBundle\Entity\Field\Field;
use Survos\PixieBundle\Entity\Field\FieldInterface;
use Survos\Tree\Traits\TreeTrait;
use Survos\Tree\TreeInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

// #[ApiResource(operations: [new Get(), new Put(), new Delete(), new Patch(), new Post(), new GetCollection()], shortName: 'field_sets', denormalizationContext: ['groups' => ['write']], normalizationContext: ['groups' => ['read', 'tree']])]
//#[Gedmo\Tree(type: 'nested')]
// DEPRECATED — #[ORM\Entity(repositoryClass: FieldSetRepository::class)]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'id' => 'exact',
    'core' => 'exact',
])]
class FieldSet implements RouteParametersInterface, \Stringable
{
    use IdTrait;
    use RouteParametersTrait;

//    TreeInterface
//    use TreeTrait;

    #[ORM\ManyToOne(Core::class, inversedBy: 'fieldSets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Core $core;

//    #[ORM\OneToMany(targetEntity: Field::class, indexBy: 'code', mappedBy: 'fieldSet', orphanRemoval: true, cascade: ['persist'])]
//    #[ORM\OrderBy([
//        'orderIdx' => 'asc',
//    ])]
    protected Collection $fields;

    #[ORM\OneToMany(targetEntity: FieldSet::class, mappedBy: 'parent')]
    #[ORM\OrderBy([
        'left' => 'ASC',
    ])]
    private $children;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $orderIdx;

//    #[Assert\Valid]
//    #[Groups(['write'])]
//    #[Gedmo\TreeParent]
//    #[ORM\ManyToOne(targetEntity: FieldSet::class, inversedBy: 'children')]
//    #[ORM\JoinColumn(name: 'ancestor_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
//    private $parent;

    #[Groups(['tree', 'read'])]
    public function getParentId(): ?Uuid
    {
        return $this->getParent() ? $this->getParent()->getId() : null;
    }

    public function __construct(?string $code = null, ?Core $core = null)
    {
        $this->initId($core->id . '-' . $code, $code);
        if ($core) {
            $core->addFieldSet($this);
        }
        $this->setLocale($core->getProjectLocale());
        $this->setOrderIdx($core->getFieldSets()->count());
        $this->fields = new ArrayCollection();
        $this->children = new ArrayCollection();
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

    public function getRelationFieldCode(): ?string
    {
        return $this->code;
    }

    public function setRelationFieldCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    /**
     * @return Collection|Field[]
     */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function getFieldByCode($code, bool $autoCreate = true): ?Field
    {
        if (! $field = $this->fields[$code] ?? null) {
            if ($autoCreate) {
                $field = new Field($code, $this->getCore(), $this);
            }
        }
        return $field;
    }

    public function addField(FieldInterface $field): self
    {
        if (! $this->fields->contains($field)) {
            $this->fields[$field->getCode()] = $field;
            $field->setFieldSet($this);
            $this->getCore()->addField($field);

            //            $field->setProjectCore($this->getProjectCore());
            //            if (empty($field->getProject())) {
            //                $field->setProject($this->getProject());
            //            }
        }
        assert($field->getFieldSet());
        return $this;
    }

    public function removeField(Field $field): self
    {
        if ($this->fields->removeElement($field)) {
            // set the owning side to null (unless already changed)
            if ($field->getFieldSet() === $this) {
                $field->setFieldSet(null);
            }
        }
        return $this;
    }

    public function getUniqueIdentifiers(): array
    {
        return $this->getCore()->getRP([
            'fieldSetId' => $this->getRelationFieldCode(),
        ]);
    }

    /**
     * @return Collection|FieldSet[]
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

//    public function addChild(FieldSet $child): self
//    {
//        if (! $this->children->contains($child)) {
//            $this->children[] = $child;
//            $child->setParent($this);
//        }
//        return $this;
//    }

//    public function removeChild(FieldSet $child): self
//    {
//        if ($this->children->removeElement($child)) {
//            // set the owning side to null (unless already changed)
//            if ($child->getParent() === $this) {
//                $child->setParent(null);
//            }
//        }
//        return $this;
//    }

    public function __toString(): string
    {
        return sprintf("%s.%s", $this->getCore(), $this->getCode());
    }
}
