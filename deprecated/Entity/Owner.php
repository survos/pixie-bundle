<?php
// DEPRECATED: use Inst instead. Kept for backward compat.

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\OwnerRepository;
use Survos\PixieBundle\Entity\Field\Field;

#[ORM\Entity(repositoryClass: OwnerRepository::class)]
#[ORM\Table(name: 'owner')]
#[ORM\UniqueConstraint(name: 'owner_code', columns: ['code'])]
//#[ApiResource(
//    normalizationContext: ['groups' => ['owner.read']],
//    denormalizationContext: ['groups' => ['owner.write']],
//)]
class Owner implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[ApiProperty(description: "Primary key for the owner record (string id).")]
    public string $id;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: false)]
    #[ApiProperty(description: "Unique business code for the owner; used in routes and lookups.")]
    public string $code;

    #[ORM\Column(length: 255, nullable: true)]
    #[ApiProperty(description: "Display name of the owner (institution or organization).")]
    public ?string $name = null;

    #[ORM\Column(length: 3, nullable: true)]
    #[ApiProperty(description: "ISO 3166-1 alpha-3 country code for the owner.")]
    public ?string $countryCode = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Source system institution identifier, if applicable.")]
    public ?int $institutionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[ApiProperty(description: "Short code identifying the upstream data source/provider.")]
    public ?string $source = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Cached count of projects under this owner (optional).")]
    public ?int $projectCount = 0;

    #[ORM\Column(length: 255, nullable: false)]
    #[ApiProperty(description: "Subdomain used for this owner in hosted environments.")]
    public ?string $subdomain = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(description: "Custom CSS to style the owner’s UI/pages.")]
    public ?string $css = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[ApiProperty(description: "City/State or similar locality information for the owner.")]
    public ?string $cityState = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Number of objects indexed in search for this owner (Meili).")]
    public ?int $meiliObjectCount = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(description: "Serialized Pixie configuration for this owner (implementation-specific).")]
    public ?string $serializedPixieConfig = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Aggregate counts (e.g., cores, fields) keyed by type.")]
    public ?array $coreCounts = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[ApiProperty(description: "Owner’s code used in Pixie metadata or deployment contexts.")]
    public ?string $pixieCode = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Arbitrary numeric/statistical metrics for dashboards.")]
    public ?array $stats = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Per-locale translation counts for owner-related data.")]
    public ?array $translationCounts = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Per-locale translation statistics (extended detail).")]
    public ?array $tranStats = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: "Total associated image count across projects/cores.")]
    public ?int $imageCount = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[ApiProperty(description: "Default locale/language code for this owner (e.g., en, es-MX).  null if multi-lingual")]
    public ?string $locale = null;

    /** @var Collection<int, Field> */
//    #[ORM\OneToMany(targetEntity: Field::class, mappedBy: 'owner', orphanRemoval: true)]
//    #[ORM\OrderBy(['orderIdx' => 'ASC'])]
    #[ApiProperty(description: "All field definitions owned by this owner, indexed per table/usage.")]
    public Collection $fields;

    /** @var Collection<int, Core> */
    #[ORM\OneToMany(targetEntity: Core::class, mappedBy: 'owner', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ApiProperty(description: "All cores that belong to this owner (aggregate children).")]
    public Collection $cores;


    public function __construct(string $id, string $code, ?string $source = null)
    {
        $this->id      = $id;
        $this->code    = $code;
        $this->source  = $source ?? $code;
        $this->subdomain = $code;

        $this->fields = new ArrayCollection();
        $this->cores  = new ArrayCollection();
        $this->tables = new ArrayCollection();
    }

    /** ------------ Relation helpers (optional, keep while migrating) ------------ */

    public function addCore(Core $core): self
    {
        if (!$this->cores->contains($core)) {
            $this->cores->add($core);
            $core->owner = $this; // owning side
        }
        return $this;
    }

    public function removeCore(Core $core): self
    {
        if ($this->cores->removeElement($core)) {
            if ($core->owner === $this) {
                $core->owner = null;
            }
        }
        return $this;
    }

    public function addField(Field $field): self
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->owner = $this; // owning side in Field
        }
        return $this;
    }

    public function removeField(Field $field): self
    {
        if ($this->fields->removeElement($field)) {
            if ($field->owner === $this) {
                $field->owner = null;
            }
        }
        return $this;
    }

    public function addTable(Table $table): self
    {
        if (!$this->tables->contains($table)) {
            $this->tables->add($table);
            $table->owner = $this; // owning side in Table
        }
        return $this;
    }

    public function removeTable(Table $table): self
    {
        if ($this->tables->removeElement($table)) {
            if ($table->owner === $this) {
                $table->owner = null;
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
