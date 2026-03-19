<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service\Enhance;

use Survos\CoreBundle\Service\SurvosUtils;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Model\Table;

final class PixieEnhancer
{
    /**
     * Apply table-driven enhancements:
     * - regex rename rules (Table::$rules)
     * - property-driven mapping + coercion (Table::$properties)
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function enhance(string $pixieCode, string $core, Table $table, array $row): array
    {
        $row = SurvosUtils::removeNullsAndEmptyArrays($row);

        // 1) Apply regex keyed rename rules (field name rewrites)
        $row = $this->applyRules($row, $table->getRules());

        // 2) Apply property-driven mapping/coercion
        foreach ($table->getProperties() as $prop) {
            if (!$prop instanceof Property) {
                // properties can be strings; ignore (they are usually parsed into Property already)
                continue;
            }

            $srcKey = $prop->getCode();

            // If the source key is missing, there is nothing to map/coerce.
            if (!array_key_exists($srcKey, $row)) {
                continue;
            }

            $value = $row[$srcKey];

            // Determine destination key:
            // - list.<termset> => map srcKey to the termset id (subType), e.g. tema -> theme
            // - otherwise keep srcKey
            $destKey = $srcKey;
            if ($prop->getType() === Property::TYPE_LIST) {
                $destKey = $prop->getListTableName() ?? $srcKey;
            }

            // Coerce value by property type/subtype
            $coerced = $this->coerceByProperty($prop, $value);

            // Write to destKey, remove srcKey if it changed
            $row[$destKey] = $coerced;
            if ($destKey !== $srcKey) {
                unset($row[$srcKey]);
            }
        }

        return SurvosUtils::removeNullsAndEmptyArrays($row);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $rules regex => replacement
     * @return array<string,mixed>
     */
    private function applyRules(array $row, array $rules): array
    {
        if ($rules === []) {
            return $row;
        }

        $out = $row;

        foreach ($rules as $pattern => $replacement) {
            if (!is_string($pattern) || $pattern === '' || !is_string($replacement)) {
                continue;
            }

            // Apply rule to keys; do not mutate during iteration over $out keys
            $keys = array_keys($out);
            foreach ($keys as $k) {
                if (!is_string($k)) {
                    continue;
                }

                $matched = @preg_match($pattern, $k);
                if ($matched !== 1) {
                    continue;
                }

                $newKey = @preg_replace($pattern, $replacement, $k);
                if (!is_string($newKey) || $newKey === '' || $newKey === $k) {
                    continue;
                }

                if (!array_key_exists($newKey, $out)) {
                    $out[$newKey] = $out[$k];
                }
                unset($out[$k]);
            }
        }

        return $out;
    }

    private function coerceByProperty(Property $prop, mixed $value): mixed
    {
        $type = $prop->getType();

        // list.* is the important case for terms: ensure list<string>
        if ($type === Property::TYPE_LIST) {
            return $this->coerceToStringList($value, $prop->getDelim());
        }

        // attribute types: int/bool/num/text/string/etc.
        return match ($type) {
            Property::PROPERTY_INT => $this->coerceInt($value),
            Property::PROPERTY_BOOL => $this->coerceBool($value),
            Property::PROPERTY_NUMERIC => $this->coerceFloat($value),
            Property::PROPERTY_ARRAY => $this->coerceToStringList($value, $prop->getDelim()),
            default => $value, // keep as-is for text/string/unknown
        };
    }

    /**
     * @return list<string>
     */
    private function coerceToStringList(mixed $value, ?string $delim): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $flat = [];
            foreach ($value as $v) {
                if ($v === null) {
                    continue;
                }
                if (is_scalar($v)) {
                    $s = trim((string) $v);
                    if ($s !== '') {
                        $flat[] = $s;
                    }
                }
            }
            return array_values(array_unique($flat));
        }

        if (is_string($value)) {
            $s = trim($value);
            if ($s === '') {
                return [];
            }

            // Prefer explicit delim; otherwise use a safe heuristic
            $parts = null;
            if (is_string($delim) && $delim !== '') {
                $parts = explode($delim, $s);
            } else {
                // common cases
                $parts = preg_split('/\s*;\s*|\s*,\s*/', $s) ?: [$s];
            }

            $parts = array_values(array_filter(array_map(
                static fn(string $x) => trim($x),
                $parts
            ), static fn(string $x) => $x !== ''));

            return array_values(array_unique($parts));
        }

        if (is_scalar($value)) {
            $s = trim((string) $value);
            return $s !== '' ? [$s] : [];
        }

        return [];
    }

    private function coerceInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value)) {
            $v = trim($value);
            $v = preg_replace('/[^\d\-]/', '', $v);
            return $v === '' ? null : (int) $v;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    private function coerceFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $v = str_replace(',', '.', trim($value));
            $v = preg_replace('/[^\d\.\-]/', '', $v);
            return $v === '' ? null : (float) $v;
        }
        return is_numeric($value) ? (float) $value : null;
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
}
