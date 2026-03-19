<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use RuntimeException;
use Survos\MeiliBundle\Service\IndexNameResolver as MeiliIndexNameResolver;
use Survos\MeiliBundle\Service\MeiliService;

/**
 * Resolve the index model class and its persisted fields from the Meili compiler-pass registry.
 *
 * IMPORTANT:
 * - Settings are looked up by RAW base name (unprefixed, no locale suffix), e.g. "larco".
 * - Network index UIDs (prefix + locale policy) are computed by Meili IndexNameResolver elsewhere.
 */
final class IndexModelResolver
{
    public function __construct(
        private readonly MeiliService $meili,
        private readonly MeiliIndexNameResolver $indexNames,
    ) {}

    /**
     * @return array{base:string, class: class-string, persisted: list<string>, schema: array<string,mixed>}
     */
    public function resolve(string $baseName): array
    {
        $baseName = strtolower(trim($baseName));

        // Raw registry is keyed by raw base name (no prefix), per your MeiliService::getRawIndexSettings()
        $raw = $this->meili->getRawIndexSettings();
        $setting = $raw[$baseName] ?? null;

        if (!$setting) {
            // Fall back: try match by stored baseName if present
            foreach ($raw as $rawName => $s) {
                if (($s['baseName'] ?? null) === $baseName) {
                    $setting = $s;
                    $baseName = (string) $rawName;
                    break;
                }
            }
        }

        if (!$setting) {
            throw new RuntimeException(sprintf('No Meili registry settings found for base "%s".', $baseName));
        }

        $class = $setting['class'] ?? null;
        if (!is_string($class) || $class === '' || !class_exists($class)) {
            throw new RuntimeException(sprintf('Invalid model class for base "%s": %s', $baseName, (string) $class));
        }

        // persisted is stored as whatever MeiliIndexPass wrote:
        // you currently store: 'persisted' => (array) $cfg->persisted
        // and MeiliIndexPass also expands persisted union into $persisted (but stores it separately)
        //
        // Pragmatic: accept either shape:
        $persisted = [];
        if (isset($setting['persisted']['fields']) && is_array($setting['persisted']['fields'])) {
            $persisted = $setting['persisted']['fields'];
        } elseif (is_array($setting['persisted'] ?? null)) {
            $persisted = $setting['persisted'];
        }

        $persisted = array_values(array_unique(array_filter(array_map('strval', $persisted))));
        if ($persisted === []) {
            // If persisted isn't explicitly set, we can fall back to schema displayed/filterable/searchable union,
            // but it's better to require persisted in your index DTOs.
            $schema = (array)($setting['schema'] ?? []);
            $fallback = array_merge(
                (array)($schema['filterableAttributes'] ?? []),
                (array)($schema['sortableAttributes'] ?? []),
                (array)($schema['searchableAttributes'] ?? [])
            );
            $persisted = array_values(array_unique(array_filter(array_map('strval', $fallback))));
        }

        return [
            'base' => $baseName,
            'class' => $class,
            'persisted' => $persisted,
            'schema' => (array)($setting['schema'] ?? []),
        ];
    }

    /**
     * Helper: base + locale -> final UID (prefix + multilingual policy applied).
     */
    public function uidFor(string $baseName, ?string $locale, string $fallbackSourceLocale = 'en'): string
    {
        $isMlFor = $this->indexNames->isMultiLingualFor($baseName, $fallbackSourceLocale);
        return $this->indexNames->uidFor($baseName, $locale, $isMlFor);
    }
}
