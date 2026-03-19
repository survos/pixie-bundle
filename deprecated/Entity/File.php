<?php

namespace Survos\PixieBundle\Entity;

use App\Entity\ProjectInterface;
use App\Repository\FileRepository;
use App\Traits\ProjectTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\Tree\Traits\TreeTrait;
use Survos\Tree\TreeInterface;

//#[Gedmo\Tree(type: "nested")]
// DEPRECATED — #[ORM\Entity(repositoryClass: FileRepository::class)]
class File implements RouteParametersInterface
{
    use RouteParametersTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

//    #[ORM\ManyToOne(inversedBy: 'files')]
//    #[ORM\JoinColumn(nullable: false)]
    protected Project $project;

    #[ORM\Column(length: 18)]
    private ?string $dirType = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isDir = null;

    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    public function getDirType(): ?string
    {
        return $this->dirType;
    }

    public function setDirType(string $dirType): self
    {
        $this->dirType = $dirType;

        return $this;
    }

    public function isIsDir(): ?bool
    {
        return $this->isDir;
    }

    public function setIsDir(?bool $isDir): self
    {
        $this->isDir = $isDir;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
