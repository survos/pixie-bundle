<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Event;

use Survos\PixieBundle\Entity\Inst;
use Symfony\Contracts\EventDispatcher\Event;

class IndexDiscoveredEvent extends Event
{
    public function __construct(
        public readonly string $indexName,
        public readonly string $pixieCode,
        public readonly string $locale,
        public readonly int $documentCount = 0,
        public readonly ?\DateTime $lastUpdate = null,
        public readonly array $indexStats = [],
        public readonly ?Inst $inst = null,
    ) {}
}
