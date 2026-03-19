<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\FieldDefinitionRepository;

#[ORM\Entity(repositoryClass: FieldDefinitionRepository::class)]
#[ORM\Table(name: 'field_def')]
#[ORM\UniqueConstraint(name: 'uniq_owner_core_header', columns: ['owner_code','core','incoming_header'])]
class FieldDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private(set) ?int $id;

    public function __construct(

        #[ORM\Column(length: 64)]
        public string $ownerCode,

        #[ORM\Column(length: 64)]
        public string $core,

        #[ORM\Column(length: 128)]
        public string $incomingHeader,

        #[ORM\Column(length: 128)]
        public string $code,

        #[ORM\Column(length: 32)]
        public string $kind,

        #[ORM\Column(length: 64, nullable: true)]
        public ?string $targetCore = null,

        #[ORM\Column(length: 8, nullable: true)]
        public ?string $delim = null,

        #[ORM\Column(type: 'boolean', nullable: true)]
        public ?bool $translatable = null,

        #[ORM\Column(type: 'integer', options: ['default' => 0])]
        public int $position = 0,

        // ── Descriptive (from profile.json) ──────────────────────────────────

        /** Fill rate 0.0–1.0: fraction of rows that have this field non-null */
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $fillRate = null,

        /** Number of distinct values seen in the collection */
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $distinctCount = null,

        // ── Inst-level overrides ──────────────────────────────────────────────

        /** Override the display label (defaults to code) */
        #[ORM\Column(length: 128, nullable: true)]
        public ?string $label = null,

        /** AI extraction prompt hint for this field */
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $prompt = null,

        /** Hide from display — for internal/technical fields */
        #[ORM\Column(type: 'boolean', options: ['default' => false])]
        public bool $hidden = false,

        /** Facetable in Meilisearch */
        #[ORM\Column(type: 'boolean', nullable: true)]
        public ?bool $facet = null,

        /** Searchable in Meilisearch */
        #[ORM\Column(type: 'boolean', nullable: true)]
        public ?bool $searchable = null,

        /** Sortable in Meilisearch */
        #[ORM\Column(type: 'boolean', nullable: true)]
        public ?bool $sortable = null,
    ) {}
}
