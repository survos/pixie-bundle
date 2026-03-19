<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use ReflectionClass;
use ReflectionProperty;
use Survos\PixieBundle\Dto\Attributes\Map as MapAttr;

final class MeiliSettingsBuilder
{
    public function __construct(private readonly DtoRegistry $registry) {}

    /**
     * Build settings for a pixie index.
     *
     * If $core is null, union settings across all DTO mappers applicable to the pixie.
     *
     * @return array{filterableAttributes: string[], sortableAttributes: string[], searchableAttributes: string[], displayedAttributes: string[]}
     */
    public function build(string $pixieCode, ?string $core = null): array
    {
        $filterable = [];
        $sortable   = [];
        $searchable = [];

        // Only use the highest-priority DTO that has an explicit `when` match.
        // Fall back to non-restricted DTOs only when no specific DTO exists.
        $hasSpecific = false;
        foreach ($this->registry->entries as $e) {
            if (!empty($e['when']) && in_array($pixieCode, $e['when'], true)) {
                $hasSpecific = true;
                break;
            }
        }

        foreach ($this->registry->entries as $e) {
            if (!empty($e['when'])   && !in_array($pixieCode, $e['when'], true))   continue;
            if (!empty($e['except']) &&  in_array($pixieCode, $e['except'], true)) continue;

            // Skip generic (no-when) DTOs when a specific one exists
            if ($hasSpecific && empty($e['when'])) continue;

            // Core filter is optional
            if ($core !== null && !empty($e['cores']) && !in_array($core, $e['cores'], true)) {
                continue;
            }

            $rc = new ReflectionClass($e['class']);

            foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                $attrs = $prop->getAttributes(MapAttr::class);
                if (!$attrs) {
                    continue;
                }

                /** @var MapAttr $map */
                $map = $attrs[0]->newInstance();
                $field = $prop->getName();

                if ($map->facet) {
                    $filterable[$field] = true;
                }
                if ($map->sortable) {
                    $sortable[$field] = true;
                }
                if ($map->searchable || $map->translatable) {
                    $searchable[$field] = true;
                }
            }
        }

        $filterable = array_keys($filterable);
        $sortable   = array_keys($sortable);
        $searchable = array_keys($searchable);

        sort($filterable);
        sort($sortable);
        sort($searchable);

        // displayedAttributes:
        // - Using ['*'] is fine if you truly want everything (and avoid drift).
        // - If you want deterministic “public fields”, build a list instead.
        return [
            'filterableAttributes' => $filterable,
            'sortableAttributes'   => $sortable,
            'searchableAttributes' => $searchable,
            'displayedAttributes'  => ['*'],
        ];
    }
}
