<?php

namespace Survos\PixieBundle\Entity\Field;

use Survos\PixieBundle\Model\InstanceData;
use Survos\PixieBundle\Repository\FieldRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\FieldSet;
use Survos\PixieBundle\Entity\Reference;

#[ORM\Entity(repositoryClass: FieldRepository::class)]
class ReferenceField extends Field
{

    #[ORM\OneToMany(mappedBy: 'referenceField', targetEntity: Reference::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $references;

    //    #[ORM\Column(nullable: true)]
//    private string $extension;

    public function __construct(?string $code = null, ?Core $core=null, ?FieldSet $fieldSet = null)
    {
        parent::__construct($code, $core, $fieldSet);
        $core->addField($this);
        if ($fieldSet) {
            $fieldSet->addField($this);
        }
        $this->setType(Field::TYPE_REFERENCE);
        $this->references = new ArrayCollection();
    }

//    public function instanceDataValue(InstanceData $instanceData)
//    {
//        return $instanceData->getAttribute($this->getCode());
//    }

    /**
     * @return Collection<int, Reference>
     */
    public function getReferences(): Collection
    {
        return $this->references;
    }

    public function addReference(Reference $ref): self
    {
        if (!$this->references->contains($ref)) {
            $this->references->add($ref);
            $ref->setReferenceField($this);
        }

        return $this;
    }

    public function removeReference(Reference $ref): self
    {
        if ($this->references->removeElement($ref)) {
            // set the owning side to null (unless already changed)
            if ($ref->getReferenceField() === $this) {
                $ref->setReferenceField(null);
            }
        }

        return $this;
    }


}
