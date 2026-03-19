<?php

// This replaces PlaceType, ObjType, ObjSource
//  Source, Type, Accession -- these could be ModuleConfigurationCategory (instead of type).
// could be CoreConfig, Structure/


namespace Survos\PixieBundle\Entity;

use App\Repository\ProjectRepository;
use Doctrine\ORM\Mapping as ORM;

// DEPRECATED — #[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

}
