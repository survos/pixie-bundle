<?php

namespace Survos\PixieBundle\Entity;

use App\Repository\InstanceTextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// DEPRECATED — #[ORM\Entity(repositoryClass: InstanceTextRepository::class)]
#[ORM\Index(name:'instance_text_core', fields: ['core'])]
class InstanceText
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'instanceTexts')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    #[ORM\ManyToOne(targetEntity: Core::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected Core $core;

    #[ORM\Column(type: Types::STRING)]
    private string $instanceId;

    #[ORM\Column(type: 'text')]
    private $label;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getCore(): ?Core
    {
        return $this->core;
    }

    public function setCore(?Core $core): self
    {
        $this->core = $core;

        return $this;
    }

    public function getInstanceId()
    {
        return $this->instanceId;
    }

    public function setInstanceId($instanceId): self
    {
        $this->instanceId = $instanceId;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }
}
