<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Contract\TranslatableByCodeInterface;
use Survos\PixieBundle\Repository\RowRepository;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: RowRepository::class)]
#[ORM\Table(name: 'row')]
#[ORM\UniqueConstraint(name: 'uniq_row_core_idwithin', columns: ['core_id', 'id_within_core'])]
#[ORM\Index(name: 'row_core', columns: ['core_id'])]
#[ORM\Index(name: 'row_core_marking', columns: ['core_id', 'marking'])]
#[Groups(['row.read'])]
class Row implements MarkingInterface, \Stringable, TranslatableByCodeInterface
{
    use MarkingTrait;

    /**
     * Runtime-only resolved strings for this request/run.
     * Populated by a postLoad listener or indexing resolver.
     *
     * Semantics:
     * - key exists => resolved value (non-empty string)
     * - key missing => not resolved; fall back to source text
     *
     * @var array<string,string>
     */
    private array $resolved = [];

    /**
     * Persisted pointers to Babel Str.code values:
     *   field => str_code
     *
     * @var array<string,string>|null
     */
    #[ApiProperty(description: 'Pointers: field => Babel Str.code')]
    #[ORM\Column(name: 'str_codes', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $strCodes = null;

    #[ApiProperty(description: 'The raw label from which translations are derived. Not translated.')]
    #[ORM\Column(name: 'raw_label', type: Types::STRING)]
    public string $rawLabel = '';

    #[ORM\ManyToOne(inversedBy: 'rows')]
    #[ORM\JoinColumn(nullable: false)]
    public Core $core;

    #[ApiProperty(description: 'Normalized object that Meilisearch indexes (key/value pairs EXCEPT translatable strings)')]
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $data = null;

    #[ApiProperty(description: 'Raw source data for debugging')]
    #[ORM\Column(name: 'raw_data', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $rawData = null;

    #[ApiProperty(description: 'Original JSON record before normalization/cleanup. Debug-only.')]
    #[ORM\Column(nullable: true)]
    public ?array $raw = null;

    #[ORM\Column(name: 'id_within_core', type: Types::STRING)]
    #[Groups(['row.read'])]
    public ?string $idWithinCore = null;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING)]
    #[Groups(['row.read'])]
    #[ApiProperty(description: 'Single key: core.code - idWithinCore')]
    public ?string $id = null;

    // Unmapped virtuals (shortcuts)
    #[ApiProperty(description: 'Translated label shortcut')]
    public string $label { get => $this->translated('label'); }

    #[ApiProperty(description: 'Translated description shortcut')]
    public string $description { get => $this->translated('description'); }

    public function __construct(?Core $core = null, ?string $idWithinCore = null)
    {
        if ($core && $idWithinCore !== null) {
            $this->core = $core;
            $this->idWithinCore = $idWithinCore;
            $this->id = self::rowIdentifier($core, $idWithinCore);

            // legacy counter – keep if you still rely on it
            $core->rowCount++;
        }
    }

    public static function rowIdentifier(Core $core, string $idWithinCore): string
    {
        return $core->code . '-' . $idWithinCore;
    }

    public function __toString(): string
    {
        return (string) ($this->id ?? '');
    }

    // ------------------------------------------------------------------
    // Str code pointers (populated during ingest)
    // ------------------------------------------------------------------

    public function bindStrCode(string $field, string $code): void
    {
        $field = trim($field);
        $code  = trim($code);

        if ($field === '' || $code === '') {
            return;
        }

        $this->strCodes ??= [];
        $this->strCodes[$field] = $code;
    }

    /**
     * @return array<string,string> field => str_code
     */
    public function getStrCodeMap(): array
    {
        $map = $this->strCodes ?? [];
        if ($map === []) {
            return [];
        }

        $out = [];
        foreach ($map as $field => $code) {
            $field = trim((string) $field);
            $code  = trim((string) $code);
            if ($field !== '' && $code !== '') {
                $out[$field] = $code;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Runtime translation resolution (set by hydrator/resolver)
    // ------------------------------------------------------------------

    public function clearResolved(): void
    {
        $this->resolved = [];
    }

    /**
     * @return array<string,string>
     */
    public function getResolvedMap(): array
    {
        return $this->resolved;
    }

    /**
     * Hydrator should call this for each translated field.
     *
     * - Null/empty value means “do not set” so fallback continues to work.
     */
    public function setResolvedTranslation(string $field, ?string $value): void
    {
        $field = trim($field);
        if ($field === '') {
            return;
        }

        $value = $value !== null ? trim($value) : '';
        if ($value === '') {
            // Do not overwrite fallback behavior
            unset($this->resolved[$field]);
            return;
        }

        $this->resolved[$field] = $value;
    }

    /**
     * Convenience alias used by hooks and templates.
     */
    public function t(string $field): string
    {
        return $this->translated($field);
    }

    // ------------------------------------------------------------------
    // Ingest helpers
    // ------------------------------------------------------------------

    public function setLabel(string $label): self
    {
        $this->rawLabel = $label;
        return $this;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    // ------------------------------------------------------------------
    // Translation-aware accessors used by virtual properties
    // ------------------------------------------------------------------

    /**
     * Return translated value if present; otherwise fall back to source text for that field.
     */
    protected function translated(string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return '';
        }

        if (isset($this->resolved[$field]) && $this->resolved[$field] !== '') {
            return $this->resolved[$field];
        }

        return $this->getSourceTextForField($field);
    }

    /**
     * What text did we hash/ensure as the source for this field?
     * This should match what PixieBabelEnsureCommand extracts.
     */
    public function getSourceTextForField(string $field): string
    {
        if ($field === 'label') {
            return trim((string) ($this->rawLabel ?? ''));
        }

        $data = $this->data ?? [];
        $val = $data[$field] ?? '';

        return is_string($val) ? trim($val) : '';
    }
}
