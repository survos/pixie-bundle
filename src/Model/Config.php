<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Model;

use Survos\PixieBundle\Entity\Inst;

final class Config
{
    public const string TYPE_SYSTEM = 'system';
    public const string TYPE_MUSEUM = 'museum';
    public const string TYPE_AGGREGATOR = 'agg';

    public const string VISIBILITY_PUBLIC = 'public';
    public const string VISIBILITY_PRIVATE = 'private';
    public const string VISIBILITY_UNLISTED = 'unlisted';

    /**
     * @param array<string,Table> $tables
     */
    public function __construct(
        public readonly string|float|null $version = null,
        public ?string $code = null,
        public ?Source $source = null,

        /** @var array<string,string> file => table */
        public array $files = [],

        /** @var array<string,Table> */
        public array $tables = [],

        public array $templates = [],

        public ?string $configFilename = null,
        public readonly string $type = self::TYPE_MUSEUM,
        public string $visibility = self::VISIBILITY_PUBLIC,

        public readonly array $data = [],

        // late-set by PixieService
        public ?string $dataDir = null,
        public ?string $pixieFilename = null,

        // late-set by owner resolver (optional)
        public ?Inst $inst = null,

        public ?BabelConfig $babel = null,
    ) {}

    public function getVersion(): string|float|int
    {
        assert($this->version !== null, sprintf('Missing version in %s (%s)', (string) $this->pixieFilename, (string) $this->code));
        return $this->version;
    }

    public function rp(): array
    {
        return ['pixieCode' => $this->code];
    }

    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    public function isMuseum(): bool
    {
        return $this->type === self::TYPE_MUSEUM;
    }

    public function getSourceLocale(string $default = 'en'): string
    {
        return $this->babel?->source ?: $default;
    }

    /**
     * @param list<string> $enabledLocales
     * @return list<string>
     */
    public function getTargetLocales(array $enabledLocales = [], string $defaultSource = 'en'): array
    {
        $source = $this->getSourceLocale($defaultSource);

        $targets = $this->babel?->targets;
        if (is_array($targets) && $targets !== []) {
            $targets = array_values(array_unique(array_filter($targets, fn(string $l) => $l !== $source)));
            return $targets;
        }

        // fallback: enabled locales minus source
        $enabledLocales = $enabledLocales ?: [$source];
        return array_values(array_unique(array_filter($enabledLocales, fn(string $l) => $l !== $source)));
    }

    public function getIgnored(): array
    {
        $ignore = $this->source?->ignore ?? [];
        if (is_string($ignore)) {
            return [$ignore];
        }
        return is_array($ignore) ? $ignore : [];
    }

    public function getTable(string $tableName): ?Table
    {
        return $this->tables[$tableName] ?? null;
    }
}
