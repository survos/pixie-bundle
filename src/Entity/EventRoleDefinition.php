<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\EventRoleDefinitionRepository;

#[ORM\Entity(repositoryClass: EventRoleDefinitionRepository::class)]
#[ORM\Table(name: 'event_role_def')]
#[ORM\UniqueConstraint(name: 'uniq_event_role', columns: ['event_definition_id','role_code'])]
class EventRoleDefinition
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column]
        private(set) ?int $id,

        #[ORM\ManyToOne(targetEntity: EventDefinition::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', name: 'event_definition_id')]
        public EventDefinition $eventDefinition,

        #[ORM\Column(length: 80, name: 'role_code')]
        public string $roleCode,          // e.g. 'creator'

        #[ORM\Column(length: 64, name: 'target_core')]
        public string $targetCore,        // e.g. 'per'

        #[ORM\Column(length: 8, options: ['default' => 'MANY'])]
        public string $cardinality = 'MANY',   // ONE|MANY
    ) {}
}
