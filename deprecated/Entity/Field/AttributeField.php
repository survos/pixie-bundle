<?php

namespace Survos\PixieBundle\Entity\Field;

use Survos\PixieBundle\Model\InstanceData;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\FieldSet;
use Survos\PixieBundle\Repository\FieldRepository;
use function Symfony\Component\String\u;

#[ORM\Entity(repositoryClass: FieldRepository::class)]
class AttributeField extends Field
{
    const ATTRIBUTE_URL = 'url';
    const ATTRIBUTE_INT = 'int';
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $attributeCode = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $attributeType = null;

    /**
     * @return string|null
     */
    public function getAttributeType(): ?string
    {
        return $this->attributeType;
    }

    /**
     * @param string|null $attributeType
     * @return AttributeField
     */
    public function setAttributeType(?string $attributeType): AttributeField
    {
        $this->attributeType = $attributeType;
        return $this;
    }


    static public function getTypeSpecificFields(): array
    {
        return ['dataType'];
    }

    public function __construct(?string $code = null, ?Core $core=null, ?FieldSet $fieldSet = null)
    {
        parent::__construct($code, $core->getOwner(), $fieldSet);
        $this->setType(Field::TYPE_ATTRIBUTE);
    }

    public function getAttributeCode(): ?string
    {
        return $this->attributeCode;
    }

    public function setAttributeCode(?string $attributeCode): self
    {
        $this->attributeCode = $attributeCode;

        return $this;
    }


    public function instanceDataValue(InstanceData $instanceData)
    {
        return $instanceData->getAttribute($this->getCode());
    }

    public function isUrl(): bool
    {
        // hack until we can property set attribute type
        return u($this->getPropertyConfig())->endsWith('.url');
        //        return $this->getAttributeType() == self::ATTRIBUTE_URL;
    }

    public function isReferenceAttribute(): bool
    {
        // hack until we can property set attribute type
        return $this->getCode() == 'references';
        //        return $this->getAttributeType() == self::ATTRIBUTE_URL;
    }

    public function getInternalIdentifier(): string
    {
        return sprintf("%s.%s", $this->getType(), $this->getDataType()??'string');
    }


}
