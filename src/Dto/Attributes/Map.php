<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Dto\Attributes;

use Attribute;

/**
 * @deprecated Use Survos\ImportBundle\Dto\Attributes\Map instead.
 *             Kept for backward compatibility with existing pixie-bundle DTOs.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Map extends \Survos\ImportBundle\Dto\Attributes\Map
{
}
