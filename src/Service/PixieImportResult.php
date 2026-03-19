<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

final class PixieImportResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly ?string $input = null,
        public readonly ?string $output = null,
        public readonly ?string $profile = null,
    ) {
    }

    public static function ok(string $input, string $output, string $profile): self
    {
        return new self(true, null, $input, $output, $profile);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
