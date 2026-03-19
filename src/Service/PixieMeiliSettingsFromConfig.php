<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Table;

final class PixieMeiliSettingsFromConfig
{
    public function indexName(string $pixieCode, string $locale): string
    {
        $safePixie = preg_replace('/[^a-zA-Z0-9_]/', '_', $pixieCode) ?: $pixieCode;
        return $safePixie . '_' . strtolower($locale);
    }

    /**
     * Build Meili settings for a pixie "family" (pixieCode) from Config models.
     *
     * Note: at compile time, the compiler pass still has arrays; this method supports
     * array input as a fallback. Prefer passing a Config object at runtime.
     *
     * @param Config|array<string,mixed> $pixieCfg
     * @return array{
     *   schema: array<string,mixed>,
     *   facets: array<string,array<string,mixed>>,
     *   fields: string[],
     *   embedders?: array,
     *   ui?: array
     * }
     */
    public function buildForPixie(string $pixieCode, Config|array $pixieCfg): array
    {
        if ($pixieCfg instanceof Config) {
            return $this->buildForConfig($pixieCode, $pixieCfg);
        }

        // Fallback path (compiler pass / raw arrays)
        return $this->buildForArray($pixieCode, $pixieCfg);
    }

    /**
     * Preferred: use the Config model and Table objects directly.
     *
     * @return array{schema:array<string,mixed>,facets:array<string,array<string,mixed>>,fields:string[],embedders?:array,ui?:array}
     */
    private function buildForConfig(string $pixieCode, Config $cfg): array
    {
        $filterable = ['core', 'pixie'];
        $sortable = [];
        $searchable = [];
        $fields = [];

        /** @var array<string,Table> $tables */
        $tables = $cfg->tables ?? [];
        foreach ($tables as $core => $table) {
            if (!$table instanceof Table) {
                continue;
            }

            // translatable fields -> searchable + fields
            foreach ((array)($table->translatable ?? []) as $f) {
                $f = trim((string) $f);
                if ($f === '') {
                    continue;
                }
                $searchable[] = $f;
                $fields[] = $f;
            }

            // properties are already in spec-string form, so reuse parsing logic
            foreach ((array)($table->properties ?? []) as $p) {
                if (!is_string($p)) {
                    continue;
                }
                $p = trim($p);
                if ($p === '') {
                    continue;
                }

                [$field, $type, $markers] = $this->parsePropertySpec($p);
                if ($field === '') {
                    continue;
                }

                $fields[] = $field;

                if ($markers['facet']) {
                    $filterable[] = $field;
                }

                if ($this->isSortableType($type)) {
                    $sortable[] = $field;
                }

                if ($this->isSearchableType($type) && $this->isCommonTextField($field)) {
                    $searchable[] = $field;
                }
            }
        }

        // sensible defaults
        foreach (['label', 'description', 'name', 'title', 'notes'] as $f) {
            $fields[] = $f;
            $searchable[] = $f;
        }

        $filterable = $this->uniq($filterable);
        $sortable   = $this->uniq($sortable);
        $searchable = $this->uniq($searchable);
        $fields     = $this->uniq($fields);

        $facets = [];
        foreach ($filterable as $field) {
            $facets[$field] = $this->defaultFacetConfig($field);
        }
        $facets = $this->orderFacets($facets, $filterable);

        $schema = [
            'displayedAttributes'  => ['*'],
            'filterableAttributes' => $filterable,
            'sortableAttributes'   => $sortable,
            'searchableAttributes' => $searchable ?: ['*'],
            'faceting' => [
                'sortFacetValuesBy' => ['*' => 'count'],
                'maxValuesPerFacet' => 1000,
            ],
        ];

        $ui = array_filter([
            'origin' => 'pixie',
            'pixie'  => $pixieCode,
            'icon'   => 'Pixie',
            'label'  => $cfg->source?->label ?? $pixieCode,
        ], static fn($v) => $v !== null && $v !== '');

        // If you add meili-specific options to Config later, wire them here.
        $embedders = [];

        return [
            'schema' => $schema,
            'facets' => $facets,
            'fields' => $fields,
            'ui' => $ui,
            'embedders' => $embedders,
        ];
    }

    /**
     * Fallback: operate on raw arrays (compile-time).
     *
     * @param array<string,mixed> $pixieCfg
     * @return array{schema:array<string,mixed>,facets:array<string,array<string,mixed>>,fields:string[],embedders?:array,ui?:array}
     */
    private function buildForArray(string $pixieCode, array $pixieCfg): array
    {
        $tables = $pixieCfg['tables'] ?? [];
        $tables = is_array($tables) ? $tables : [];

        $filterable = ['core', 'pixie'];
        $sortable = [];
        $searchable = [];
        $fields = [];

        foreach ($tables as $core => $tableCfg) {
            if (!is_array($tableCfg)) {
                continue;
            }

            $translatable = $tableCfg['translatable'] ?? [];
            if (is_array($translatable)) {
                foreach ($translatable as $f) {
                    $f = trim((string) $f);
                    if ($f === '') continue;
                    $searchable[] = $f;
                    $fields[] = $f;
                }
            }

            $props = $tableCfg['properties'] ?? [];
            if (!is_array($props)) {
                continue;
            }

            foreach ($props as $p) {
                if (!is_string($p)) {
                    continue;
                }
                $p = trim($p);
                if ($p === '') continue;

                [$field, $type, $markers] = $this->parsePropertySpec($p);
                if ($field === '') continue;

                $fields[] = $field;

                if ($markers['facet']) {
                    $filterable[] = $field;
                }
                if ($this->isSortableType($type)) {
                    $sortable[] = $field;
                }
                if ($this->isSearchableType($type) && $this->isCommonTextField($field)) {
                    $searchable[] = $field;
                }
            }
        }

        foreach (['label', 'description', 'name', 'title', 'notes'] as $f) {
            $fields[] = $f;
            $searchable[] = $f;
        }

        $filterable = $this->uniq($filterable);
        $sortable   = $this->uniq($sortable);
        $searchable = $this->uniq($searchable);
        $fields     = $this->uniq($fields);

        $facets = [];
        foreach ($filterable as $field) {
            $facets[$field] = $this->defaultFacetConfig($field);
        }
        $facets = $this->orderFacets($facets, $filterable);

        $schema = [
            'displayedAttributes'  => ['*'],
            'filterableAttributes' => $filterable,
            'sortableAttributes'   => $sortable,
            'searchableAttributes' => $searchable ?: ['*'],
            'faceting' => [
                'sortFacetValuesBy' => ['*' => 'count'],
                'maxValuesPerFacet' => 1000,
            ],
        ];

        $ui = array_filter([
            'origin' => 'pixie',
            'pixie'  => $pixieCode,
            'icon'   => $pixieCfg['ui']['icon'] ?? 'Pixie',
            'label'  => $pixieCfg['source']['label'] ?? $pixieCfg['code'] ?? $pixieCode,
        ], static fn($v) => $v !== null && $v !== '');

        return [
            'schema' => $schema,
            'facets' => $facets,
            'fields' => $fields,
            'ui' => $ui,
            'embedders' => $pixieCfg['meili']['embedders'] ?? [],
        ];
    }

    /**
     * Parse a property spec like:
     *   "weight:double"
     *   "marking:text#"
     *   "citation?g=global"
     *
     * @return array{0:string,1:string,2:array{facet:bool}}
     */
    private function parsePropertySpec(string $spec): array
    {
        $spec = preg_replace('/\s+#.*$/', '', $spec) ?? $spec;
        $spec = trim($spec);

        $field = $spec;
        $type = 'text';

        if (str_contains($spec, ':')) {
            [$field, $type] = array_map('trim', explode(':', $spec, 2));
        } else {
            $field = preg_replace('/[?].*$/', '', $spec) ?? $spec;
        }

        $facet = false;

        // marker convention: trailing "#" means facet
        if (str_ends_with($field, '#')) {
            $facet = true;
            $field = rtrim($field, '#');
        }
        if (str_ends_with($type, '#')) {
            $facet = true;
            $type = rtrim($type, '#');
        }

        return [trim($field), trim($type), ['facet' => $facet]];
    }

    private function isSortableType(string $type): bool
    {
        $t = strtolower($type);
        return (bool) preg_match('/\b(int|long|double|float|decimal|date|datetime|timestamp)\b/', $t);
    }

    private function isSearchableType(string $type): bool
    {
        $t = strtolower($type);
        return (bool) preg_match('/\b(text|string|memo)\b/', $t);
    }

    private function isCommonTextField(string $field): bool
    {
        return in_array($field, ['label','description','name','title','notes','keywords','summary','caption','bio'], true);
    }

    /** @return array<string,mixed> */
    private function defaultFacetConfig(string $field): array
    {
        $widget = 'RefinementList';
        if (preg_match('/(year|count|age|price|score|rating|runtime|length|weight|height|width|depth|min|max)$/i', $field)) {
            $widget = 'RangeSlider';
        }

        return [
            'label'            => $this->humanize($field),
            'order'            => 0,
            'showMoreThreshold'=> 8,
            'widget'           => $widget,
            'type'             => $widget, // soft-compat
            'format'           => null,
            'visible'          => null,
            'tagsAny'          => [],
            'props'            => [],
            'sortMode'         => 'count',
            'collapsed'        => false,
            'limit'            => null,
            'showMoreLimit'    => null,
            'searchable'       => null,
            'lookup'           => [],
        ];
    }

    private function humanize(string $name): string
    {
        $s = preg_replace('/([a-z])([A-Z])/u', '$1 $2', $name);
        $s = str_replace('_', ' ', (string) $s);
        return ucfirst($s);
    }

    /**
     * @param array<string,array<string,mixed>> $facetMap
     * @param string[] $filterable
     * @return array<string,array<string,mixed>>
     */
    private function orderFacets(array $facetMap, array $filterable): array
    {
        $ordered = [];
        foreach ($filterable as $f) {
            if (isset($facetMap[$f])) {
                $ordered[$f] = $facetMap[$f];
            }
        }
        foreach ($facetMap as $k => $v) {
            if (!isset($ordered[$k])) {
                $ordered[$k] = $v;
            }
        }
        return $ordered;
    }

    /** @param string[] $a @return string[] */
    private function uniq(array $a): array
    {
        $a = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $a), static fn($v) => $v !== '')));
        return $a;
    }
}
