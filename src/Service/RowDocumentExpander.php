<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Model\Config;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Expand a Row into a Meili-ready document.
 *
 * Strategy:
 *  1) Base system keys: id/pixie/core/_meta
 *  2) Expand normalized payload ($row->data) using a field allow-list derived from Config/YAML
 *     (fallback: include all payload fields if allow-list is empty)
 *  3) Overlay translated hook outputs for any fields present in strCodes (label/description/etc.)
 */
final class RowDocumentExpander
{
    public function __construct(
        private readonly PropertyAccessorInterface $accessor,
        private readonly PixieMeiliSettingsFromConfig $settingsFromConfig,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function expand(Row $row, Config $config, string $pixieCode, string $coreCode, string $indexedLocale, string $sourceLocale): array
    {
        $doc = [
            'id' => $row->id,
            'pixie' => $pixieCode,
            'core' => $coreCode,
            '_meta' => [
                'sourceLocale' => $sourceLocale,
                'indexedLocale' => $indexedLocale,
            ],
        ];

        // 1) payload expansion (non-translatable fields)
        $payload = $row->data ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }

        $built = $this->settingsFromConfig->buildForPixie($pixieCode, $config);
        $allowed = $built['fields'] ?? [];

        if (is_array($allowed) && $allowed !== []) {
            foreach ($allowed as $field) {
                $field = trim((string) $field);
                if ($field === '' || $field === 'id' || $field === 'pixie' || $field === 'core' || $field === '_meta') {
                    continue;
                }
                if (array_key_exists($field, $payload)) {
                    $doc[$field] = $payload[$field];
                }
            }
        } else {
            // fallback: include all normalized fields
            foreach ($payload as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                if (isset($doc[$k]) || $k === '_meta') {
                    continue;
                }
                $doc[$k] = $v;
            }
        }

        // 2) overlay translations for strCode fields via hooks
        foreach (array_keys($row->getStrCodeMap()) as $field) {
            if ($this->accessor->isReadable($row, $field)) {
                $doc[$field] = $this->accessor->getValue($row, $field);
            } else {
                $doc[$field] ??= null;
            }
        }

        return $doc;
    }
}
