<?php

namespace Survos\PixieBundle\Entity;

use App\Repository\AttributeTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// DEPRECATED — #[ORM\Entity(repositoryClass: AttributeTypeRepository::class)]
class AttributeType implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $caId;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(max: 255, maxMessage: 'The attribute name cannot be longer than {{ limit }} characters')]
    private $name;

    #[ORM\OneToMany(targetEntity: CustomFieldType::class, mappedBy: 'attributeType')]
    private $customFieldTypes;

    public function __construct()
    {
        $this->customFieldTypes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaId(): ?int
    {
        return $this->caId;
    }

    public function setCaId(?int $caId): self
    {
        $this->caId = $caId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection|CustomFieldType[]
     */
    public function getCustomFieldTypes(): Collection
    {
        return $this->customFieldTypes;
    }

    public function addCustomFieldType(CustomFieldType $customFieldType): self
    {
        if (! $this->customFieldTypes->contains($customFieldType)) {
            $this->customFieldTypes[] = $customFieldType;
            $customFieldType->setAttributeType($this);
        }

        return $this;
    }

    public function removeCustomFieldType(CustomFieldType $customFieldType): self
    {
        if ($this->customFieldTypes->removeElement($customFieldType)) {
            // set the owning side to null (unless already changed)
            if ($customFieldType->getAttributeType() === $this) {
                $customFieldType->setAttributeType(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->getName();
    }
}
