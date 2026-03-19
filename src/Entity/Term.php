<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Contract\TranslatableByCodeInterface;
use Survos\PixieBundle\Repository\TermRepository;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: TermRepository::class)]
#[ORM\Table(name: 'term')]
#[ORM\UniqueConstraint(name: 'uniq_term_set_code', columns: ['set_id', 'code'])]
#[Groups(['term.read'])]
class Term implements \Stringable, TranslatableByCodeInterface
{
    /**
     * Runtime-only resolved strings for this request/run.
     * @var array<string,string>
     */
    private array $resolved = [];

    /**
     * Persisted pointers to Babel Str.code values:
     * field => str_code
     * @var array<string,string>|null
     */
    #[ORM\Column(name: 'str_codes', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $strCodes = null;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING)]
    public string $id; // e.g. "cul:mochica"

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'set_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public TermSet $termSet;

    #[ORM\Column(type: Types::STRING)]
    public string $code; // e.g. "mochica" (facet value)

    #[ORM\Column(name: 'raw_label', type: Types::STRING)]
    public string $rawLabel = ''; // source label text (untranslated)

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $count = null; // optional frequency

    // Unmapped virtual
    public string $label { get => $this->translated('label'); }

    public function __construct(TermSet $set, string $code)
    {
        $this->termSet = $set;
        $this->code = $code;
        $this->id = self::makeId($set->id, $code);
    }

    public static function makeId(string $setId, string $code): string
    {
        return $setId . ':' . $code;
    }

    public function __toString(): string
    {
        return $this->id;
    }

    // ------------------------------------------------------------------
    // Str code pointers
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

    /** @return array<string,string> */
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

    /** @return array<string,string> */
    public function getResolvedMap(): array
    {
        return $this->resolved;
    }

    public function setResolvedTranslation(string $field, ?string $value): void
    {
        $field = trim($field);
        if ($field === '') {
            return;
        }

        $value = $value !== null ? trim($value) : '';
        if ($value === '') {
            unset($this->resolved[$field]);
            return;
        }

        $this->resolved[$field] = $value;
    }

    public function t(string $field): string
    {
        return $this->translated($field);
    }

    // ------------------------------------------------------------------
    // Source text
    // ------------------------------------------------------------------

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

    public function getSourceTextForField(string $field): string
    {
        if ($field === 'label') {
            return trim((string) ($this->rawLabel ?? ''));
        }
        return '';
    }
}
