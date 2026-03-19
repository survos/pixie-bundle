<?php

namespace Survos\PixieBundle\Entity\Field;

use App\Repository\FieldRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\FieldSet;
use Survos\PixieBundle\Entity\Relation;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: FieldRepository::class)]
class RelationField extends Field
{
    #[ORM\ManyToOne(targetEntity: Core::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private $relatedCore;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Groups(['field.relation'])]
    private $relationFieldCode;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['field.relation'])]
    private $reverseRelationFieldCode;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $relationCount;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $isReverse=false;

    /**
     * @return bool|null
     */
    public function getIsReverse(): ?bool
    {
        return $this->isReverse;
    }

    /**
     * @param bool|null $isReverse
     * @return RelationField
     */
    public function setIsReverse(?bool $isReverse): RelationField
    {
        $this->isReverse = $isReverse;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Relation::class, mappedBy: 'relationField', orphanRemoval: true)]
    private $relations;


    public function __construct(?string   $code,
                                string   $reverseCode,
                                Core     $leftCore,
                                Core     $rightCore,
                                ?FieldSet $fieldSet = null)
    {
        $this->relations = new ArrayCollection();
        $this->relatedCore = $rightCore;
        $this->isReverse = false;
//        dump($code . ' in ' . $this::class);
        parent::__construct($code, $leftCore, $fieldSet);
        $this
            ->setInternalCode($this->getLCode());
        if ($reverseCode) {
            $this->setReverseRelationFieldCode($reverseCode);
        }
        $this->setType(Field::TYPE_RELATION);
    }

    /**
     * @return mixed
     */
    public function getRelationFieldCode(): ?string
    {
        return $this->relationFieldCode;
    }

    /**
     * @param mixed $relationFieldCode
     * @return RelationField
     */
    public function setRelationFieldCode($relationFieldCode)
    {
        $this->relationFieldCode = $relationFieldCode;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getReverseRelationFieldCode(): ?string
    {
        return $this->reverseRelationFieldCode;
    }

    public function getReverseCode(): ?string
    {
        return $this->getReverseRelationFieldCode();
    }

    /**
     * @param mixed $reverseRelationFieldCode
     * @return RelationField
     */
    public function setReverseRelationFieldCode(string $reverseRelationFieldCode): self
    {
        $this->reverseRelationFieldCode = $reverseRelationFieldCode;
        return $this;
    } // e.g. 'created'


    /**
     * @return Core|null
     */
    public function getRelatedCore(): ?Core
    {
        return $this->relatedCore;
    }

    /**
     * @return Core|null
     * @deprecated "Use getRelatedCore()"
     */
    public function getRelatedProjectCore(): ?Core
    {
        return $this->getRelatedCore();
    }

    public function setRelatedCore(?Core $relatedCore): self
    {
        $this->relatedCore = $relatedCore;

        return $this;
    }

    public function getRelatedCoreCode(): ?string
    {
        return $this->getRelatedCore()?->getCode();
    }

    static public function getTypeSpecificFields(): array
    {
        return ['relatedProjectCore', 'RelationFieldCode', 'reverseRelationFieldCode'];
    }

    public function __toString(): string
    {
        return $this->getFullCode();
    }


    public function getDisplay(): string
    {
        return sprintf('%s (%s)', $this->getLabel(), $this->getRelatedCore()->getCode());
    }


    public function getLeftCore(): Core
    {
        return $this->getCore();
    }

    public function getRightCore(): Core
    {
        return $this->getRelatedCore();
    }

    #[Groups(['rt.export'])]
    public function getLeftCoreCode(): string
    {
        return $this->getLeftCore()->getCoreCode();
    }

    #[Groups(['rt.export'])]
    public function getRightCoreCode(): string
    {
        return $this->getRightCore()->getCoreCode();
    }

    public function getFullCode(): string
    {
        return $this->key3($this->getLeftCore(), $this->getCode(), $this->getRightCore());
    }

    public function getRCode(): string
    {
        return sprintf("%s.%s", $this->getCode(), $this->getRightCoreCode());
    }

    public function getLCode(): string
    {
        return sprintf("%s.%s", $this->getLeftCore(), $this->getCode());
    }

    public function getRevCode(): string
    {
        return sprintf("%s.%s", $this->getRightCoreCode(), $this->getReverseCode());
    }

    public static function key3(Core $left, string $code, Core $right)
    {
        return sprintf("%s.%s.%s", $left->getCoreCode(), $code, $right->getCoreCode());
    }

    public function getRelationCount(): ?int
    {
        return $this->relationCount;
    }

    public function setRelationCount(?int $relationCount): self
    {
        $this->relationCount = $relationCount;

        return $this;
    }

    public function incRelationCount(): self
    {
        $this->relationCount++;
        return $this;
    }

    /**
     * @return Collection|Relation[]
     */
    public function getRelations(): Collection
    {
        return $this->relations;
    }

    public function addRelation(Relation $relation): self
    {
        if (!$this->relations->contains($relation)) {
            $this->relations[] = $relation;
            $relation->setRelationField($this);
        }

        return $this;
    }

    public function removeRelation(Relation $relation): self
    {
        if ($this->relations->removeElement($relation)) {
            // set the owning side to null (unless already changed)
            if ($relation->getRelationField() === $this) {
                $relation->setRelationField(null);
            }
        }

        return $this;
    }

    public function getReverseField(): RelationField
    {
        $relationField = $this->getRightCore()->getFieldByCode($this->getReverseCode(), throwErrorIfMissing: true);
        if (empty($relationField)) {
            echo "Missing reverse relation field for " . $this->getCode();
        }
        return $relationField;
    }

    public function incrementInstanceCount($inc = 1, bool $incReverse = true): self
    {
        $this->instanceCount += $inc;
        if ($incReverse) {
            assert($this->getReverseRelationFieldCode(), "Missing reverse code in " . $this->getCode());
            $this->getReverseField()->incrementInstanceCount(incReverse: false); // avoid loop!
        }
        return $this;
    }

    public function getInternalIdentifier(): string
    {
        $related =  $this->getRelatedCore();
        assert($related, "Missing related Core");
        return sprintf("%s.%s", $this->getType(),$related ?: 'undefined');
    }


}
