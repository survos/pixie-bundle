<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

final class TermCodeGenerator
{
    public function codeFromLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        // transliterate accents -> ascii
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        if (!is_string($s) || $s === '') {
            $s = $label;
        }

        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        $s = preg_replace('/-+/', '-', $s) ?? $s;
        $s = trim($s, '-');

        return $s;
    }
}
