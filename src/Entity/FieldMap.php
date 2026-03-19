<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

/**
 * @deprecated Entity removed — stub kept for backward compat.
 * Only slugify() is still used by LarcoService.
 */
class FieldMap
{
    public static function slugify(string $text): string
    {
        // Convert "Código de catálogo" → "codigo_de_catalogo"
        $text = mb_strtolower($text);
        $text = str_replace(['á','é','í','ó','ú','ü','ñ','ä','ö'], ['a','e','i','o','u','u','n','a','o'], $text);
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
    }
}
