<?php

namespace Survos\PixieBundle\Entity;

use App\Entity\IdInterface;
use App\Entity\ProjectInterface;
use App\Repository\InstanceCategoryRepository;
use Survos\PixieBundle\Traits\IdTrait;
use App\Traits\ProjectTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

// DEPRECATED — #[ORM\Entity(repositoryClass: InstanceCategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'category_instance', columns: ['category_id', 'instance_id'])]
#[UniqueEntity(['category', 'instance'])]

class InstanceCategory implements
    \Stringable
{
    use IdTrait;

    #[ORM\ManyToOne(inversedBy: 'instanceCategories', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Instance $instance = null;

    #[ORM\ManyToOne(inversedBy: 'instanceCategories', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Category $category = null;

//    #[ORM\ManyToOne(inversedBy: 'instanceCategories')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    public function __construct(Instance $instance, Category $category)
    {
        $this->initId();
        $this->setCode(sprintf("%s.%s", $instance->getCode(), $category->getCode()));
        $instance->addInstanceCategory($this);
        $category->addInstanceCategory($this);
        //        $this->setProject($category->getProject());
        //        $this->setCategoryTypeCode($category->getCategoryTypeCode());
    }

    public static function create(Instance $instance, Category $category): self
    {
        $instanceCategory = new self($instance, $category);
        $category->addInstanceCategory($instanceCategory);
        $instance->addInstanceCategory($instanceCategory);
        return $instanceCategory;
    }

    public function getInstance(): ?Instance
    {
        return $this->instance;
    }

    public function setInstance(?Instance $instance): self
    {
        $this->instance = $instance;
        if ($instance) {
            $this->setProject($instance->getProject());
        }

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getCategoryTypeCode(): ?string
    {
        return $this->getCategory()->getCategoryTypeCode();
    }

    public function getCategoryCode(): ?string
    {
        return $this->getCategory()->getCode();
    }

    public function __toString()
    {
        // this could be slow if it's lazy-loaded
        return sprintf("%s.%s", $this->getCategoryCode(), $this->getInstance()->getCode());

    }
}
