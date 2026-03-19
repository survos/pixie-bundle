<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\EventParticipantRepository;

#[ORM\Entity(repositoryClass: EventParticipantRepository::class)]
#[ORM\Table(name: 'event_participant')]
#[ORM\UniqueConstraint(name: 'uniq_event_role_actor', columns: ['event_id','role_code','actor_row_id'])]
#[ORM\Index(name: 'idx_actor_role', columns: ['actor_row_id','role_code'])]
class EventParticipant
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column]
        private(set) ?int $id = null,

        #[ORM\ManyToOne(targetEntity: Event::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public ?Event $event=null,

        #[ORM\Column(length: 80, name: 'role_code')]
        public ?string $roleCode=null,

        #[ORM\ManyToOne(targetEntity: Row::class)]
        #[ORM\JoinColumn(name: 'actor_row_id', nullable: false, onDelete: 'CASCADE')]
        public ?Row $actor=null,

        #[ORM\Column(type: 'boolean', options: ['default' => true])]
        public bool $certain = true,
    ) {}
}
