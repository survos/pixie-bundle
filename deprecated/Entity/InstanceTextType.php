<?php

namespace Survos\PixieBundle\Entity;

use App\Repository\InstanceTextTypeRepository;
use Doctrine\ORM\Mapping as ORM;

// DEPRECATED — #[ORM\Entity(repositoryClass: InstanceTextTypeRepository::class)]
class InstanceTextType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'instanceTextTypes')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    #[ORM\ManyToOne(targetEntity: Core::class, inversedBy: 'instanceTextTypes')]
    #[ORM\JoinColumn(nullable: false)]
    protected $core;

    #[ORM\Column(type: 'string', length: 64)]
    private $code;

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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }
}
