<?php
// packages/pixie-bundle/src/Contract/TranslatableByCodeInterface.php
declare(strict_types=1);

namespace Survos\PixieBundle\Contract;

interface TranslatableByCodeInterface
{
    /** @return array<string,string|null> field => Str.code (or null) */
    public function getStrCodeMap(): array;
    public function setResolvedTranslation(string $field, ?string $value): void;

}
