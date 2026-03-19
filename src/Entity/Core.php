<?php

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\CoreRepository;
// use Survos\PixieBundle\Entity\Field\Field;   // DEPRECATED
// use Survos\PixieBundle\Entity\FieldSet;       // DEPRECATED
// use Survos\PixieBundle\Entity\CustomField;    // DEPRECATED

#[ORM\Entity(repositoryClass: CoreRepository::class)]
#[ORM\Table(name: 'core')]
#[ORM\UniqueConstraint(name: 'core_unique', columns: ['code'])]
#[ORM\Index(name: 'IDX_CORE_INST', columns: ['inst_id'])]
#[ORM\Index(name: 'IDX_CORE_CORE_CODE', columns: ['core_code'])]
class Core implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[ApiProperty(description: "Primary key for the core record (string id).")]
    public string $id;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: false)]
    #[ApiProperty(description: "Unique business code for the core within an owner.")]
    public string $code;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    #[ApiProperty(description: "Short core type code (e.g., obj, loc, per).")]
    public ?string $coreCode = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    #[ApiProperty(description: "Whether the core is currently enabled/visible.")]
    public ?bool $isEnabled = null;

    #[ORM\ManyToOne(inversedBy: 'cores', targetEntity: Inst::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[ApiProperty(description: "Owning owner record (aggregate root).")]
    public ?Inst $inst = null;

    // DEPRECATED relations removed: FieldSet, CustomField, Field, InstanceTextType, Reference, Category

    #[ORM\Column]
    #[ApiProperty(description: "Configuration summary values for this core (dashboards/metrics).")]
    public array $configSummary = [];

//    /** @var Collection<int, Instance> */
//    #[ORM\OneToMany(mappedBy: 'core', targetEntity: Instance::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
//    #[ApiProperty(description: "Domain instances (business rows) that live in this core.")]
//    public Collection $instances; // not used

    // DEPRECATED: $relations (Relation entity removed)

    /** @var Collection<int, Sheet> */
    #[ApiProperty(description: "Spreadsheet/sheet descriptors associated with this core (if used).")]
    public Collection $sheets;

    #[ORM\Column(length: 255, nullable: true)]
    #[ApiProperty(description: "Internal code of the primary field used for identification.")]
    public ?string $fieldCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[ApiProperty(description: "Human-readable label of the primary field.")]
    public ?string $fieldLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[ApiProperty(description: "Description for the primary field.")]
    public ?string $fieldDescription = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Ordering index for UI or internal sorting.")]
    public ?int $orderIdx = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[ApiProperty(description: "Field code that acts as the core's ID/business key.")]
    public ?string $idFieldCode = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Optional schema definition (structure & constraints) for this core.")]
    public ?array $schema = [];

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Number of records indexed in Meilisearch for this core.")]
    public ?int $meiliCount = null;

    /** @var Collection<int, Row> */
    #[ORM\OneToMany(targetEntity: Row::class, mappedBy: 'core', orphanRemoval: true)]
    #[ApiProperty(description: "Logical rows for this core; may back various views.")]
    public Collection $rows;

    #[ORM\Column]
    #[ApiProperty(description: "Cached total number of rows associated with this core.")]
    public int $rowCount = 0;

    public function __construct(string $id, string $code, ?Inst $inst = null)
    {
        $this->id   = $id;
        $this->code = $code;
        $this->inst = $inst;
        $this->instances         = new ArrayCollection();
        $this->relations         = new ArrayCollection();
        $this->sheets            = new ArrayCollection();
        $this->rows              = new ArrayCollection();
    }

    /** ------------ Relation helpers (optional, keep while migrating) ------------ */

    public function addFieldSet(FieldSet $fieldSet): self
    {
        if (!$this->fieldSets->contains($fieldSet)) {
            $this->fieldSets->add($fieldSet);
            $fieldSet->core = $this;
        }
        return $this;
    }

    public function removeFieldSet(FieldSet $fieldSet): self
    {
        if ($this->fieldSets->removeElement($fieldSet)) {
            if ($fieldSet->core === $this) {
                $fieldSet->core = null;
            }
        }
        return $this;
    }

    public function addField(Field $field): self
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->core = $this;
        }
        return $this;
    }

    public function removeField(Field $field): self
    {
        if ($this->fields->removeElement($field)) {
            if ($field->core === $this) {
                $field->core = null;
            }
        }
        return $this;
    }

    public function addCustomField(CustomField $customField): self
    {
        if (!$this->customFields->contains($customField)) {
            $this->customFields->add($customField);
            $customField->core = $this;
        }
        return $this;
    }

    public function removeCustomField(CustomField $customField): self
    {
        if ($this->customFields->removeElement($customField)) {
            if ($customField->core === $this) {
                $customField->core = null;
            }
        }
        return $this;
    }

    public function addInstanceTextType(InstanceTextType $type): self
    {
        if (!$this->instanceTextTypes->contains($type)) {
            $this->instanceTextTypes->add($type);
            $type->core = $this;
        }
        return $this;
    }

    public function removeInstanceTextType(InstanceTextType $type): self
    {
        if ($this->instanceTextTypes->removeElement($type)) {
            if ($type->core === $this) {
                $type->core = null;
            }
        }
        return $this;
    }

    public function addReference(Reference $ref): self
    {
        if (!$this->references->contains($ref)) {
            $this->references->add($ref);
            $ref->core = $this;
            $this->referenceCount++;
        }
        return $this;
    }

    public function removeReference(Reference $ref): self
    {
        if ($this->references->removeElement($ref)) {
            if ($ref->core === $this) {
                $ref->core = null;
            }
            $this->referenceCount = max(0, $this->referenceCount - 1);
        }
        return $this;
    }

    public function addCategory(Category $cat): self
    {
        if (!$this->categories->contains($cat)) {
            $this->categories->add($cat);
            $cat->core = $this;
        }
        return $this;
    }

    public function removeCategory(Category $cat): self
    {
        if ($this->categories->removeElement($cat)) {
            if ($cat->core === $this) {
                $cat->core = null;
            }
        }
        return $this;
    }

    public function addInstance(Instance $instance): self
    {
        if (!$this->instances->contains($instance)) {
            $this->instances->add($instance);
            $instance->core = $this;
            $this->instanceCount++;
        }
        return $this;
    }

    public function removeInstance(Instance $instance): self
    {
        if ($this->instances->removeElement($instance)) {
            if ($instance->core === $this) {
                $instance->core = null;
            }
            $this->instanceCount = max(0, $this->instanceCount - 1);
        }
        return $this;
    }

    public function addRelation(Relation $rel): self
    {
        if (!$this->relations->contains($rel)) {
            $this->relations->add($rel);
            $rel->core = $this;
        }
        return $this;
    }

    public function removeRelation(Relation $rel): self
    {
        if ($this->relations->removeElement($rel)) {
            if ($rel->core === $this) {
                $rel->core = null;
            }
        }
        return $this;
    }

    public function addSheet(Sheet $sheet): self
    {
        if (!$this->sheets->contains($sheet)) {
            $this->sheets->add($sheet);
            $sheet->core = $this;
        }
        return $this;
    }

    public function removeSheet(Sheet $sheet): self
    {
        if ($this->sheets->removeElement($sheet)) {
            if ($sheet->core === $this) {
                $sheet->core = null;
            }
        }
        return $this;
    }

    public function addRow(Row $row): self
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
            $row->core = $this;
            $this->rowCount++;
        }
        return $this;
    }

    public function removeRow(Row $row): self
    {
        if ($this->rows->removeElement($row)) {
            if ($row->core === $this) {
                $row->core = null;
            }
            $this->rowCount = max(0, $this->rowCount - 1);
        }
        return $this;
    }

    public function __toString()
    {
        return $this->code;
    }
}
