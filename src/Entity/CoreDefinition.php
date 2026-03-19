<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\CoreDefinitionRepository;

#[ORM\Entity(repositoryClass: CoreDefinitionRepository::class)]
#[ORM\Table(name: 'core_def')]
class CoreDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private(set) ?int $id = null;


    public function __construct(
        #[ORM\Column(length: 64, name: 'owner_code')]
        public string $ownerCode,

        #[ORM\Column(length: 64)]
        public string $core,

        #[ORM\Column(length: 64)]
        public string $pk,

        #[ORM\Column(length: 16, options: ['default' => 'v1'])]
        public string $schemaVersion = 'v1',
    ) {}
}
