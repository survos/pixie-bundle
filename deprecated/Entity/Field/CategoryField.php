<?php

        // aka Classification, like relations, but are specific to a primary table, e.g. Location Type, Object Type.
//    perhaps if we supported filtered relations, we could combine them.

namespace Survos\PixieBundle\Entity\Field;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Entity\Category;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\FieldSet;

#[ORM\Entity(repositoryClass: FieldRepository::class)]
class CategoryField extends Field
{
    public const TYPE = 'type';
    public const SOURCE = 'source';
    public const ACCESSION = 'accession';
    public const DEACCESSION = 'deaccession';
    public const STATUS = 'status'; // generic, like 'celdula printed'
    public const CONDITION = 'cond';
    public const CURATED_SECTION = 'section';
//    public const LOCATION = 'loc';
    public const CATEGORY_TYPES = [
        self::TYPE, self::SOURCE, self::ACCESSION,
        self::DEACCESSION, self::CURATED_SECTION, self::CONDITION, self::STATUS
    ]; // , self::LABEL];

    #[ORM\OneToMany(Category::class, mappedBy: 'categoryField', cascade: ['persist'], orphanRemoval: true)]
    private Collection $categories;

    public function __construct(Core $core, ?string $code = null, ?FieldSet $fieldSet = null)
    {
        $this->categories = new ArrayCollection();
        parent::__construct($code, $core, $fieldSet);
        $this->setType(Field::TYPE_CATGORY);
    }

    public function getRootCategory(): ?Category
    {
        // return the first one without a parent.  This isn't very good.
        foreach ($this->getCategories() as $category) {
            if (! $category->getParent()) {
                return $category;
            }
        }
        return null;
    }

    /**
     * @return int
     */
    public function getCategoryCount(): int
    {
        return $this->categoryCount;
    }

    public function setCategoryCount(int $categoryCount): self
    {
        $this->categoryCount = $categoryCount;
        return $this;
    }

    #[ORM\Column]
    private int $categoryCount = 0;

    /**
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function getCategoriesAsQuickstartString()
    {
    }

    public function addCategory(Category $category): self
    {
        if (! $this->categories->contains($category)) {
            $this->categories->add($category);
            $category->setCategoryField($this);
//            $category->setProjectCore($category)
            $this->getCore()->addCategory($category);

            $this->categoryCount++;
        }
        assert($category->getCategoryField(), "missing categoryField");

        return $this;
    }

    public function removeCategory(Category $category): self
    {
        if ($this->categories->removeElement($category)) {
            $this->categoryCount--;
            // set the owning side to null (unless already changed)
            if ($category->getCategoryField() === $this) {
                $category->setCategoryField(null);
            }
        }

        return $this;
    }

    public function getCategoryByCode(string $catgoryCode): ?Category
    {
        return $this->getCategories()->filter(fn (Category $category) => Category::slug($catgoryCode) === $category->getCode())->first() ?: null;
    }

    public static function getTypeSpecificFields(): array
    {
        return ['internalCode'];
    }

    public function getDisplay(): string
    {
        return sprintf('%s (%s)', $this->getLabel() ?: '@' . $this->getCode(), $this->getInternalCode());
    }
}
