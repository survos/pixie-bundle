<?php

namespace Survos\PixieBundle\Entity\Field;

use Survos\PixieBundle\Model\InstanceData;
use App\Repository\FieldRepository;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\FieldSet;

#[ORM\Entity(repositoryClass: FieldRepository::class)]
class MeasurementField extends Field // arguably this is a measurement attribute, not field
{

    #[ORM\Column(nullable: true)]
    private array $dimensions = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $units = null;


    static public function getTypeSpecificFields(): array
    {
        return ['units', 'dimensions'];
    }

    public function __construct(?string $code = null, ?Core $core=null, ?FieldSet $fieldSet = null)
    {
        parent::__construct($code, $core, $fieldSet);
        $this->setType(Field::TYPE_MEASUREMENT);
    }

    public function instanceDataValue(InstanceData $instanceData)
    {
        return $instanceData->getAttribute($this->getCode());
    }


    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    public function setDimensions(?array $dimensions): self
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    public function getUnits(): ?string
    {
        return $this->units;
    }

    public function setUnits(?string $units): self
    {
        $this->units = $units;

        return $this;
    }


}
