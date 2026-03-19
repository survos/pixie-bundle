<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\EventRepository;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'event')]
#[ORM\Index(name: 'idx_event_subject', columns: ['subject_row_id','event_definition_id'])]
#[ORM\Index(name: 'idx_event_place',   columns: ['place_row_id'])]
class Event
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column]
        private(set) int $id,

        #[ORM\ManyToOne(targetEntity: Row::class)]
        #[ORM\JoinColumn(name: 'subject_row_id', nullable: false, onDelete: 'CASCADE')]
        public Row $subject,

        #[ORM\ManyToOne(targetEntity: EventDefinition::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', name: 'event_definition_id')]
        public EventDefinition $definition,

        #[ORM\ManyToOne(targetEntity: Row::class)]
        #[ORM\JoinColumn(name: 'place_row_id', nullable: true, onDelete: 'SET NULL')]
        public ?Row $place = null,

        #[ORM\Column(type: Types::STRING, nullable: true, name: 'time_label')]
        public ?string $timeLabel = null,

        #[ORM\Column(type: Types::STRING, nullable: true, name: 'time_start')]
        public ?string $timeStart = null,

        #[ORM\Column(type: Types::STRING, nullable: true, name: 'time_end')]
        public ?string $timeEnd = null,

        #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true], name: 'time_certain')]
        public bool $timeCertain = true,

        #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true], name: 'place_certain')]
        public bool $placeCertain = true,

        #[ORM\Column(type: Types::STRING, nullable: true)]
        public ?string $note = null,

        #[ORM\Column(type: Types::STRING, length: 191, nullable: true, unique: false, name: 'identity_hash')]
        public ?string $identityHash = null,
    ) {}
}
