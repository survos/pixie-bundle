<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\TermSetRepository;

#[ORM\Entity(repositoryClass: TermSetRepository::class)]
#[ORM\Table(name: 'term_set')]
final class TermSet
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    public string $id; // e.g. cul, mat, theme, time, epoch

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $label = null; // optional UI label (not translated yet)

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $sourceLocale = null;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $pixieCode = null;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
