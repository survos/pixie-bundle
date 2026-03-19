<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Dto\Attributes\Core as CoreAttr;
use Survos\ImportBundle\Dto\Attributes\Map as MapAttr;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Reflection-based mapper for DTOs using #[Map] / #[Core].
 */
final class DtoMapper
{
    /**
     * @param array<string,mixed> $record
     * @template T of object
     * @param class-string<T> $dtoClass
     * @param array{pixie?:string, core?:string} $context
     * @return T
     */
    public function mapRecord(array $record, string $dtoClass, array $context = []): object
    {
        $rc  = new ReflectionClass($dtoClass);
        $dto = $rc->newInstanceWithoutConstructor();

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $this->resolveValue($prop, $record, $context);
            $value = $this->coerceToPropertyType($prop, $value);
            if ($value !== null || $this->isNullable($prop)) {
                $prop->setValue($dto, $value);
            }
        }

        if (method_exists($dto, 'afterMap')) {
            $norm = $this->toArray($dto);
            $dto->afterMap($norm, $record);
            $this->applyArray($dto, $norm);
        }

        return $dto;
    }

    /** @return array<string,mixed> */
    public function toArray(object $dto): array
    {
        $out = [];
        $rc = new ReflectionClass($dto);
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            $out[$p->getName()] = $p->getValue($dto);
        }
        return $out;
    }

    private function applyArray(object $dto, array $data): void
    {
        $rc = new ReflectionClass($dto);
        foreach ($data as $k => $v) {
            if ($rc->hasProperty($k)) {
                $p = $rc->getProperty($k);
                if ($p->isPublic()) {
                    $p->setValue($dto, $v);
                }
            }
        }
    }

    private function resolveValue(ReflectionProperty $prop, array $record, array $context): mixed
    {
        $map = $this->getAttribute($prop, MapAttr::class);
        $core= $this->getAttribute($prop, CoreAttr::class);

        // honor when/except on Map
        if ($map) {
            $pix = $context['pixie'] ?? null;
            if ($pix && $map->when && !in_array($pix, $map->when, true)) {
                return null;
            }
            if ($pix && $map->except && in_array($pix, $map->except, true)) {
                return null;
            }
        }

        // 1) pick value via #[Map]
        $val = null;
        if ($map) {
            if ($map->source !== null && array_key_exists($map->source, $record)) {
                $val = $record[$map->source];
            } elseif ($map->regex !== null) {
                $val = $this->findByRegex($record, $map->regex);
            } elseif (array_key_exists($prop->getName(), $record)) {
                $val = $record[$prop->getName()];
            }
            if ($map->if === 'isset' && $val === null) {
                return null; // leave default
            }
            if (is_string($val) && $map->delim && $this->isArrayProperty($prop)) {
                $parts = preg_split('/\s*'.preg_quote($map->delim,'/').'\s*/', $val) ?: [];
                $val = array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
            }
        } else {
            // 2) default: try exact property name
            if (array_key_exists($prop->getName(), $record)) {
                $val = $record[$prop->getName()];
            }
        }

        // 3) if #[Core], leave as-is (relation planner handles it)
        if ($core) {
            return $val;
        }

        return $val;
    }

    private function findByRegex(array $record, string $regex): mixed
    {
        $pattern = $regex;
        if (!str_starts_with($pattern, '/')) {
            $pattern = '/'.$pattern.'/i';
        }
        foreach ($record as $k => $v) {
            if (@preg_match($pattern, (string)$k)) {
                if (preg_match($pattern, (string)$k)) {
                    return $v;
                }
            }
        }
        return null;
    }

    private function isArrayProperty(ReflectionProperty $prop): bool
    {
        $t = $prop->getType();
        return $t instanceof ReflectionNamedType && $t->getName() === 'array';
    }

    private function isNullable(ReflectionProperty $prop): bool
    {
        $t = $prop->getType();
        return $t instanceof ReflectionNamedType && $t->allowsNull();
    }

    private function coerceToPropertyType(ReflectionProperty $prop, mixed $value): mixed
    {
        if ($value === null) return null;
        $t = $prop->getType();
        if (!$t instanceof ReflectionNamedType) return $value;
        $name = $t->getName();

        return match ($name) {
            'string'  => is_string($value) ? $value : (is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)),
            'int'     => is_int($value) ? $value : (is_numeric($value) ? (int)$value : null),
            'float'   => is_float($value) ? $value : (is_numeric($value) ? (float)$value : null),
            'bool'    => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'array'   => is_array($value) ? $value : (is_string($value) ? [$value] : (array)$value),
            'object'  => is_object($value) ? $value : (is_array($value) ? json_decode(json_encode($value), false) : (object)['value'=>$value]),
            default   => $value,
        };
    }

    /** @template T of object */
    private function getAttribute(ReflectionProperty $prop, string $attributeClass): ?object
    {
        $attrs = $prop->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF);
        if (!$attrs) return null;
        return $attrs[0]->newInstance();
    }
}
