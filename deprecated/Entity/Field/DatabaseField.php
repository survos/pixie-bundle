<?php

namespace Survos\PixieBundle\Entity\Field;

// FieldMap->Field, which can be a customField, or intrin.
// A custom field can have 0+ fields

// There is also Property...
// and Attribute

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\FieldSet;
use Survos\PixieBundle\Entity\Instance;
use Survos\PixieBundle\Repository\FieldRepository;

#[ORM\Entity(repositoryClass: FieldRepository::class)]
//#[ORM\UniqueConstraint(name: 'pc_field_code', columns: ['project_id', 'core_id', 'code'])]
class DatabaseField extends Field
//    implements
//    RouteParametersInterface,
//    ProjectCoreInterface,
//    IdInterface,
//    ProjectInterface,
//    UuidAttributeInterface,
//    LabelInterface,
//    \Stringable
{
//    use IdTrait;
//    use ProjectTrait;
//    use UuidAttributeTrait;
//    use LabelTrait;
//    use ProjectCoreTrait;



public function __set($property, $value) {
    if ($property == 'name') {
        assert(false);
    }
}
    public function __construct(?string $code = null, ?Core $core=null, ?FieldSet $fieldSet = null, ?string $internalCode=null)
    {
//        dump($code . ' in ' . $this::class);
        parent::__construct($code, $core->getOwner(), $fieldSet);
        $this->setType(Field::TYPE_INTRINSIC);
        if ($internalCode) {
            $this->setInternalCode($internalCode);
            if ($internalCode == Instance::DB_CODE_FIELD) {
                $core->setIdFieldCode($code);
            }
        }
    }

    static public function getTypeSpecificFields(): array
    {
        return ['internalCode'];
    }

    public function setValue(mixed $value) {

    }

    public function getDisplay(): string
    {
        return sprintf('%s (db.%s)', $this->getLabel(), $this->getInternalCode()); // , $this->getInternalCode());
    }


}
