<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Model;

final class BabelConfig
{
    /**
     * @param list<string>|null $targets
     */
    public function __construct(
        public ?string $source = null,
        public ?array  $targets = null,
        public ?string $engine = null,
    ) {}
}
