<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

/**
 * Parse Pixie property specs into term field definitions.
 *
 * Examples:
 *  - "cul:list.cul[@label]"   => field=cul, set=cul, multi=true
 *  - "mat:list.mat[@label]"   => field=mat, set=mat, multi=true (but runtime value can still be scalar)
 *  - You can later add "mat:term.mat[@label]" for explicit single.
 */
final class TermSpecParser
{
    /**
     * @param list<string> $properties table->properties
     * @return array<string,array{set:string,multi:bool}> keyed by target field name (e.g. 'cul')
     */
    public function termFieldsFromProperties(array $properties): array
    {
        $out = [];

        foreach ($properties as $spec) {
            if (!is_string($spec)) {
                continue;
            }
            $spec = trim($spec);
            if ($spec === '' || !str_contains($spec, ':')) {
                continue;
            }

            [$field, $type] = array_map('trim', explode(':', $spec, 2));
            if ($field === '' || $type === '') {
                continue;
            }

            // Remove trailing markers (#, ?g=...) from type
            $type = preg_replace('/[?#].*$/', '', $type) ?? $type;

            // We treat list.* as "terms" (multi); you can introduce term.* later.
            if (str_starts_with($type, 'list.')) {
                $set = $this->parseSetFromType($type);
                if ($set) {
                    $out[$field] = ['set' => $set, 'multi' => true];
                }
            } elseif (str_starts_with($type, 'terms.')) {
                $set = $this->parseSetFromType($type);
                if ($set) {
                    $out[$field] = ['set' => $set, 'multi' => true];
                }
            } elseif (str_starts_with($type, 'term.')) {
                $set = $this->parseSetFromType($type);
                if ($set) {
                    $out[$field] = ['set' => $set, 'multi' => false];
                }
            }
        }

        return $out;
    }

    private function parseSetFromType(string $type): ?string
    {
        // list.cul[@label] -> cul
        // list.theme[@label] -> theme
        $t = $type;

        // strip prefix list.|term.|terms.
        $t = preg_replace('/^(list|term|terms)\./', '', $t) ?? $t;

        // strip suffix [@...] if present
        $t = preg_replace('/\[.*$/', '', $t) ?? $t;

        $t = trim($t);
        return $t !== '' ? $t : null;
    }
}
