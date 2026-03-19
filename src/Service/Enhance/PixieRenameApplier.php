<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service\Enhance;

use Survos\CoreBundle\Service\SurvosUtils;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\IndexModelResolver;

/**
 * Apply config-driven normalization to a raw record:
 * - rename keys (sourceKey => canonicalKey)
 * - split certain string fields into arrays
 * - coerce types based on the index model DTO property types
 */
final class PixieRenameApplier
{
    public function __construct(
        private readonly IndexModelResolver $models,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function apply(string $pixieCode, string $core, Config $cfg, array $row): array
    {
        $row = SurvosUtils::removeNullsAndEmptyArrays($row);

        $table = $cfg->getTable($core);

        // 1) Rename rules from config
        $rename = $this->tableMap($table, 'rename');
        if ($rename !== []) {
            $row = $this->applyRenameMap($row, $rename);
        }

        // 2) Split rules from config (optional)
        $split = $this->tableMap($table, 'split'); // canonicalField => delimiter
        if ($split !== []) {
            $row = $this->applySplitRules($row, $split);
        }

        // 3) Type coercion based on DTO
        // If an index model exists for this pixieCode, coerce to match.
        $resolved = $this->models->resolve($pixieCode);
        $class = $resolved['class'] ?? null;
        $persisted = $resolved['persisted'] ?? null;

        if (is_string($class) && class_exists($class) && is_array($persisted) && $persisted !== []) {
            $row = $this->coerceToDto($row, $class, $persisted);
        }

        return $row;
    }

    /**
     * @param mixed $table
     * @return array<string,mixed>
     */
    private function tableMap(mixed $table, string $key): array
    {
        if (!$table) {
            return [];
        }

        // Most robust: property exists on table DTO
        if (is_object($table) && property_exists($table, $key) && is_array($table->{$key})) {
            /** @var array<string,mixed> $m */
            $m = $table->{$key};
            return $m;
        }

        // Alternate: getter
        $getter = 'get' . ucfirst($key);
        if (is_object($table) && method_exists($table, $getter)) {
            $m = $table->{$getter}();
            return is_array($m) ? $m : [];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $rename sourceKey => destKey
     * @return array<string,mixed>
     */
    private function applyRenameMap(array $row, array $rename): array
    {
        foreach ($rename as $from => $to) {
            if (!is_string($from) || $from === '' || !is_string($to) || $to === '') {
                continue;
            }
            if (!array_key_exists($from, $row)) {
                continue;
            }
            if (!array_key_exists($to, $row)) {
                $row[$to] = $row[$from];
            }
            unset($row[$from]);
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $split canonicalField => delimiter
     * @return array<string,mixed>
     */
    private function applySplitRules(array $row, array $split): array
    {
        foreach ($split as $field => $delimiter) {
            if (!is_string($field) || $field === '' || !isset($row[$field])) {
                continue;
            }
            if (!is_string($delimiter) || $delimiter === '') {
                continue;
            }

            $v = $row[$field];

            // already an array? leave it.
            if (is_array($v)) {
                continue;
            }

            if (is_string($v)) {
                $parts = array_values(array_filter(array_map(
                    static fn(string $s) => trim($s),
                    explode($delimiter, $v)
                ), static fn(string $s) => $s !== ''));

                $row[$field] = $parts;
            }
        }

        return $row;
    }

    /**
     * Coerce fields in $row to match DTO property types for persisted fields.
     *
     * @param array<string,mixed> $row
     * @param class-string $dtoClass
     * @param list<string> $persisted
     * @return array<string,mixed>
     */
    private function coerceToDto(array $row, string $dtoClass, array $persisted): array
    {
        $rc = new \ReflectionClass($dtoClass);

        foreach ($persisted as $field) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $row)) {
                continue;
            }

            // only coerce if the property exists
            if (!$rc->hasProperty($field)) {
                continue;
            }

            $prop = $rc->getProperty($field);
            $type = $prop->getType();

            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            $builtin = $type->isBuiltin() ? $type->getName() : null;
            if ($builtin === null) {
                continue;
            }

            $row[$field] = $this->coerceValue($row[$field], $builtin);
        }

        return $row;
    }

    private function coerceValue(mixed $value, string $builtin): mixed
    {
        // null stays null
        if ($value === null) {
            return null;
        }

        return match ($builtin) {
            'array' => $this->coerceArray($value),
            'bool'  => $this->coerceBool($value),
            'int'   => $this->coerceInt($value),
            'float' => $this->coerceFloat($value),
            'string'=> is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }

    private function coerceArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            // conservative default: split on commas
            $parts = array_values(array_filter(array_map(
                static fn(string $s) => trim($s),
                preg_split('/[;,]/', $value) ?: []
            ), static fn(string $s) => $s !== ''));
            return $parts;
        }
        // scalar -> single-element list
        if (is_scalar($value)) {
            return [(string) $value];
        }
        return [];
    }

    private function coerceBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['1','true','t','yes','y','on'], true);
        }
        return (bool) $value;
    }

    private function coerceInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value)) {
            $v = trim($value);
            return (int) preg_replace('/[^\d\-]/', '', $v);
        }
        return (int) $value;
    }

    private function coerceFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $v = str_replace(',', '.', trim($value));
            $v = preg_replace('/[^\d\.\-]/', '', $v);
            return (float) $v;
        }
        return (float) $value;
    }
}
