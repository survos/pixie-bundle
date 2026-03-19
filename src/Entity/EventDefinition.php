<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\EventDefinitionRepository;

#[ORM\Entity(repositoryClass: EventDefinitionRepository::class)]
#[ORM\Table(name: 'event_def')]
#[ORM\UniqueConstraint(name: 'uniq_owner_code', columns: ['owner_code','code'])]
class EventDefinition
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column]
        private(set) int $id,

        #[ORM\Column(length: 64, name: 'owner_code')]
        public string $ownerCode,

        #[ORM\Column(length: 80)]
        public string $code,

        #[ORM\Column(length: 160)]
        public string $label,

        #[ORM\Column(length: 64, name: 'subject_core')]
        public string $subjectCore,       // e.g. 'obj'

        #[ORM\Column(length: 64, nullable: true, name: 'place_core')]
        public ?string $placeCore = 'loc',

        #[ORM\Column(length: 32, options: ['default' => 'interval'], name: 'time_model')]
        public string $timeModel = 'interval',   // 'instant'|'interval'
    ) {}
}
