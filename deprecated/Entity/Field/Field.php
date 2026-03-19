<?php // at the OWNER level, maps from pixie Column model

namespace Survos\PixieBundle\Entity\Field;

// FieldMap->Field, which can be a customField, or intrin.
// A custom field can have 0+ fields

// There is also Property...
// and Attribute

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\GridGroupBundle\CsvSchema\Parser;
use Survos\GridGroupBundle\Model\Property;
use Survos\PixieBundle\Entity\Category;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\CoreInterface;
use Survos\PixieBundle\Entity\CustomField;
use Survos\PixieBundle\Entity\FieldSet;
use Survos\PixieBundle\Entity\ImportKeyInterface;
use Survos\PixieBundle\Entity\Instance;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Model\InstanceData;
use Survos\PixieBundle\Repository\FieldRepository;
use Survos\PixieBundle\Traits\CoreIdTrait;
use Survos\PixieBundle\Traits\IdTrait;
use Survos\PixieBundle\Traits\StatsTrait;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use function Symfony\Component\String\u;

#[ORM\Entity(repositoryClass: FieldRepository::class)]
//#[ORM\UniqueConstraint(name: 'pc_field_code', fields: ['project', 'core', 'code'])]
//#[ORM\UniqueConstraint(name: 'pc_field_internal_code', columns: ['owner_id', 'core_id', 'internal_code'])]
#[ORM\UniqueConstraint(name: 'owner_field', columns: ['owner_id', 'code'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
//#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap(['intrinsic' => DatabaseField::class,
    'cat' => CategoryField::class,
    'ref' => ReferenceField::class,
    'meas' => MeasurementField::class,
    'attr' => AttributeField::class,
    'rel' => RelationField::class])]

#[UniqueEntity('id')]
//#[Assert\EnableAutoMapping()]
class Field implements
//    AccessInterface,
    RouteParametersInterface,
    FieldInterface,
//    IdInterface,
//    StatsInterface,
//    ProjectInterface,
//    ImportKeyInterface,
//    UuidAttributeInterface,
//    TranslatableFieldsProxyInterface,
    CoreInterface,
    \Stringable
{
//    use ProjectCoreTrait;
    use IdTrait;
    use CoreIdTrait;
//    use ProjectTrait;
    use StatsTrait;
//    use UuidAttributeTrait;
//    use TranslatableFieldsProxyTrait;
//    use ImportKeyTrait;
//    use AccessTrait;
//    use RouteParametersTrait;

    public const TRANSLATION_CODE = 'field';
    static function getTranslationCode():string { return self::TRANSLATION_CODE; } // candidate for trait

    final const FIELD_CLASSES = [DatabaseField::class, RelationField::class, CategoryField::class, AttributeField::class, ReferenceField::class, MeasurementField::class,
        Field::class
    ];
    final const PLACE_NEW = 'new';
    final const PLACE_DEPRECATED = 'deprecated';

    final public const TYPE_MEASUREMENT = 'mea';
//    final public const TYPE_MEDIA = 'media';
    final public const TYPE_REFERENCE = 'ref';
    final public const TYPE_RELATION = 'rel';
    final public const TYPE_CATGORY = 'cat';
    final public const TYPE_INTRINSIC = 'db';
    final public const TYPE_ATTRIBUTE = 'att';

    final public const TYPE_LIST = 'list';

    final public const TYPE_UNKNOWN = 'unknown';

    final public const TYPES = [self::TYPE_RELATION, self::TYPE_CATGORY,  self::TYPE_INTRINSIC, self::TYPE_ATTRIBUTE,
        self::TYPE_REFERENCE,
        self::TYPE_MEASUREMENT, self::TYPE_LIST, self::TYPE_UNKNOWN];

//    #[Groups(['spreadsheet', 'tree', 'read', 'write', 'preview', 'instance.read'])]
//    #[ORM\Column(type: 'string', length: 255, nullable: false)]
//    #[Assert\Length(max: 255, maxMessage: 'The code cannot be longer than {{ limit }} characters')]
//    #[Assert\NotBlank()]
//    private string $code;
//
//    public function getCode(): string
//    {
//        //        if (!isset($this->code)) { return self::class . ' no-code'; }
//        assert(isset($this->code), $this::class . " code not set!");
//        return $this->code;
//    }
//
//    public function setCode(string $code): self
//    {
//        assert($code <> 'obj.intrinsic.images', $this::class);
//        //        $code = substr($code, 0, 255);
//        assert(strlen($code) <= 255, "$code is too long");
////        assert(!preg_match('/\s/', $code), "code $code contains spaces");
//        $code = str_replace('/\s/', '', $code); // should really fix at the call level
//        $this->code = $code;
////        if (empty($this->name)) {
////            $this->name = $code;
////        }
//        return $this;
//    }
//
    #[ORM\ManyToOne(targetEntity: Core::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Core $core;

    #[ORM\ManyToOne(targetEntity: FieldSet::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: true)]
    protected ?FieldSet $fieldSet=null;


    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['field.database','field.category','fieldMap.read'])]
    private ?string $internalCode = null;

    public function isTranslatable(): bool
    {
        return in_array($this->getInternalCode(), Instance::TRANSLATABLE_FIELDS);
    }
        public static function getTranslationEntityClass(): string
    {

        return FieldTranslation::class;
        $explodedNamespace = explode('\\', __CLASS__);
        $entityClass = array_pop($explodedNamespace);

        dd($entityClass, $explodedNamespace);
        return '\\' . implode('\\', $explodedNamespace) . '\\Translation\\' . $entityClass . 'Translation';

    }


    /**
     * @return string|null
     */
    public function getInternalCode(): ?string
    {
        return $this->internalCode;
    }

    public function getRelationFieldCode(): ?string
    {
        return null;
    }


    /**
     * @param string|null $internalCode
     * @return Field
     */
    public function setInternalCode(?string $internalCode): self
    {
        assert(!str_contains($internalCode, '@'), $internalCode);
        $this->internalCode = $internalCode;
        return $this;
    } // e.g. label (database), type (cateogry), etc..

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\NotNull()]
    private $orderIdx;

//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'fields', cascade: ['persist'])]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
//    protected Project $project;

    #[ORM\Column(type:Types::JSON, nullable: true, options: ['jsonb'=>true])]
//    #[ORM\Column(type:Types::JSON, nullable: true)]
    private $settings = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    #[Assert\Length(max: 32, maxMessage: 'The field.type cannot be longer than {{ limit }} characters')]
    #[Groups(['field.read','field.write'])]
    private ?string $type=null;

    #[ORM\ManyToOne(targetEntity: CustomField::class, inversedBy: 'fields')]
    private $customField;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CategoryField $categoryField = null;

    #[ORM\Column(nullable: true)]
    private ?bool $multiField = null;


    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], nullable: true)]
    private $aliases = [];

    #[ORM\Column(nullable: true)]
    protected int $instanceCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rules = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $propertyConfig = null;

    #[ORM\Column(nullable: true)]
    private ?int $facetCount = null;

    #[ORM\ManyToOne(inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Inst $inst = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tableNames = null;


    public function __construct(?string $code = null, ?Inst $inst=null) // Core $core, FieldSet $fieldSet = null)
    {
        assert($code);
        assert(strlen($code));
//        assert(false, $code);

        $this->initId(id: self::createId($code, $owner), code: $code);
        if ($owner) {
            $owner->addField($this);
            if (!$this->getOrderIdx()) {
                $this->setOrderIdx($owner->getFields()->count()+1);
            };
        }

//        $this->setLocale($owner->getLocale());
//        $this->setTranslations(new ArrayCollection());
//        $this->setOrderIdx($this->getCore()->getFields()->count() * 10);
//        $this->type = $this->getClassFromFieldType()
    }

    static public function createId(string $code, ?Inst $inst=null): string
    {
        return $code; // for now, skip owner, too messy
//        return sprintf("%s-%s", $owner->code, $code);
    }

    public function isMultiField(): ?bool
    {
        return $this->multiField;
    }

    public function getProperty(): ?Property
    {
        return Parser::parseConfigHeader($this->getPropertyConfig());
    }

    public function setMultiField(?bool $multiField): self
    {
        $this->multiField = $multiField;

        return $this;
    }

    public function getDataAccessCode(): string
    {
        if (in_array($this::class, [DatabaseField::class, Category::class])) {
            return $this->getInternalCode();
        } else {
            return $this->getCode();
        }

    }
    public function instanceDataValue(InstanceData $instanceData) {
        return match($this::class) {
            DatabaseField::class => $instanceData->getDb($this->getInternalCode()),
            AttributeField::class => $instanceData->getAttribute($this->getCode()),
            CategoryField::class => $instanceData->getCat($this->getInternalCode()), // or the regular code??
            RelationField::class => $instanceData->getRel($this->getRcode()),
            default => assert(false, "missing " . $this::class),
        };
    }

    public function valueCountsAsKeyValue($sorted = true): array
    {
        $kv = [];
        foreach ($this->getValueCounts($sorted) as $key=>$value) {
            $kv[] = [
                'key' => $key,
                'value' => $value
            ];
        }
        return $kv;
    }

    public function mDataValue(array|object $mData, $throwErrorIfMissing = false) {
        $mData = (array) $mData;
        $throwErrorIfMissing && AppService::assertKeyExists($this->getCode(), $mData);
        $value = $mData[$this->getCode()] ?? null;
        return json_encode($value);
        dd($mData, $this->getCode());
        return json_encode($mData);
        dd($mData, $this::class);
        return match($this::class) {
            DatabaseField::class => $instanceData->getDb($this->getInternalCode()),
            AttributeField::class => $instanceData->getAttribute($this->getCode()),
            CategoryField::class => $instanceData->getCat($this->getInternalCode()), // or the regular code??
            RelationField::class => $instanceData->getRel($this->getRcode()),
            default => assert(false, "missing " . $this::class),
        };
    }

    #[Groups(['field.read','fieldMap.read'])]
    public function getJsonKeyCode()
    {
        return match($this::class) {
            DatabaseField::class => $this->getInternalCode(),
            ReferenceField::class,
            CategoryField::class,
            AttributeField::class => $this->getCode(),
            RelationField::class => $this->getRCode(),
            default => assert(false, "missing " . $this::class),
        };

    }


    public function getClassFromFieldType(): string
    {
        return match ($this->type) {
            self::TYPE_RELATION => RelationField::class,
            self::TYPE_ATTRIBUTE => AttributeField::class,
            self::TYPE_CATGORY => CategoryField::class,
            self::TYPE_INTRINSIC => DatabaseField::class,
            self::TYPE_REFERENCE => ReferenceField::class,
            default => assert(false, "No class for type: " . $this->getType())
        };
    }

    /**
     * Reverses getFormCode
     *
     * @param $formCode
     * @return bool|string|\Symfony\Component\String\UnicodeString|void
     */
    public function getCodeFromFormCode($formCode) {
        return match ($this->getType()) {
            self::TYPE_RELATION => $this->getCodeForForm(),
            self::TYPE_ATTRIBUTE => u($formCode)->trimPrefix('attr-'),
            self::TYPE_CATGORY => u($formCode)->trimPrefix('cat-'),
            self::TYPE_INTRINSIC => $formCode,
            default => assert(false, "Invalid type: " . $this->getType())
        };
    }
    public function getFormCode(): ?string
    {
        return match ($this->getType()) {
            self::TYPE_RELATION => $this->getCodeForForm(),
            self::TYPE_ATTRIBUTE => 'attr-' . $this->getCode(),
            self::TYPE_CATGORY => 'cat-' . $this->getCode(),
            self::TYPE_INTRINSIC => $this->getInternalCode(),
            self::TYPE_REFERENCE => null, // $this->getCode(),
            default => assert(false, "Invalid type: " . $this->getType())
        };
    }

    public function getFieldSet(): ?FieldSet
    {
        return $this->fieldSet;
    }

    public function setFieldSet(FieldSet $fieldSet): self
    {
        $this->fieldSet = $fieldSet;
        //        if ($fieldSet) {
        //            $fieldSet->addField($this);
        //        }

        return $this;
    }

    public function getOrderIdx(): ?int
    {
        return $this->orderIdx;
    }

    public function setOrderIdx(?int $orderIdx): self
    {
        $this->orderIdx = $orderIdx;

        return $this;
    }

    public function getSubtype(): ?string
    {

        $parts = explode('.', $this->getPropertyConfig());
        return array_pop($parts);
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): self
    {
        $this->settings = $settings;

        return $this;
    }

    public function getDisplay(): string
    {
        return $this->getLabel() ?: u($this->getCode())->title()->toString();
    }

    public function __toString(): string
    {
        return sprintf("%s.%s", $this->getFieldSet(), $this->getCode());

        //        return sprintf("%s.%s-%s", $this->getFieldSet()->getProjectCore()->getCoreCode(), $this->getFieldSet()->getRelationFieldCode(), $this->getRelationFieldCode());
    }

    public function getUniqueIdentifiers(): array
    {
        $fieldParmas  =  [strtolower( (new \ReflectionClass($this))->getShortName() ) . 'Id' => $this->getId()];
        // send both fieldId and specific id, but probably both aren't ncessary
        return $this->getCore()->getRP(
            [
                'fieldId' => $this->getCode(),
                lcfirst( (new \ReflectionClass($this))->getShortName() ) . 'Id' => $this->getCode()
            ]
        );
    }

    public function getType(): ?string
    {
        // @todo: get rid of this.
        assert($this->getClassFromFieldType() == AppService::actualClass($this), "class mismatch " . $this::class . ' ' . $this->type);
//        assert(false, 'type is no longer used or accurate');
        return $this->type;
    }

    public function setType(string $type): self
    {
//        assert(false, "no longer necessary to set $type in ". $this::class);
//        assert($type == $this->getShortClass(), sprintf("%s <> %s", $type, $this->getShortClass()));
        assert(in_array($type, (new \ReflectionClass(Field::class))->getConstants()), $type . ' is not a valid field type');
        $this->type = $type;
//        assert($this->getClassFromFieldType() == $this::class, "class mismatch " . $this::class . ' ' . $this->type . ' ' . $this->getClassFromFieldType());


        return $this;
    }


    public function getCustomField(): ?CustomField
    {
        return $this->customField;
    }

    public function setCustomField(?CustomField $customField): self
    {
        $this->customField = $customField;

        return $this;
    }




    public function getCodeForForm(): string
    {
        return $this->getCode();
    }


    public function isAttribute(): bool
    {
        return $this->is(self::TYPE_ATTRIBUTE);
    }
//    public function isReference(): bool
//    {
//        return $this->is(self::TYPE_REFERENCE);
//    }

    public function isReference(): bool
    {
        return $this::class == ReferenceField::class;
    }



    public function isRelation(): bool
    {
        return $this->is(self::TYPE_RELATION);
    }

    public function getShortClass(): string
    {
        // same
        return (new \ReflectionClass($this))->getShortName();
    }

    public function isCategory(): bool
    {
        return $this->is(self::TYPE_CATGORY);
    }
    public function isDatabase(): bool
    {
        return $this->is(self::TYPE_INTRINSIC);
    }


    private function is(string $type): bool
    {
        return $this->getType() === $type;
    }

    public function getDataType(): ?string
    {
        return $this->dataType;
    }

    public function setDoctrineDataType(?string $dataType): self
    {
        assert(in_array($dataType, (new \ReflectionClass(Types::class))->getConstants()), $dataType . ' is not a valid field type');
        $this->dataType = $dataType;

        return $this;
    }

    // each derived class will handle how to load the data
    public function loadFieldDefinition(array $fieldDef)
    {

        dd($this::class, self::class, $fieldDef);
        // optionsResolver?
    }

    static public function fieldClassMap(): array
    {
        $map = [];
        foreach (self::FIELD_CLASSES as $FIELD_CLASS) {
            $map[(new \ReflectionClass($FIELD_CLASS))->getShortName()] = $FIELD_CLASS;
        }
        return $map;
    }



    public function getAliases(): ?array
    {
        return $this->aliases;
    }

    public function setAliases(?array $aliases): self
    {
        $this->aliases = $aliases;
        return $this;
    }

    public function addAlias($columnName)
    {
        if (! in_array($columnName, $this->aliases)) {
            array_push($this->aliases, $columnName);
        }
        return $this;
    }



    static public function getTypeSpecificFields(): array
    {
        return [];
    }

    public function hasInternalCode(): bool
    {
        return in_array($this::class, [CategoryField::class, DatabaseField::class]);
    }

    public function getInstanceCount(): int
    {
        return $this->instanceCount;
    }

    public function setInstanceCount(?int $instanceCount): self
    {
        $this->instanceCount = $instanceCount;

        return $this;
    }
    public function incrementInstanceCount($inc=1): self
    {
        $this->instanceCount+=$inc;
        return $this;
    }

    public function getShowRoute()
    {
        return $this->getType() . '_field_show';
    }

    public function getRules(): ?string
    {
        return $this->rules;
    }

    public function setRules(?string $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function getPropertyConfig(): ?string
    {
        return $this->propertyConfig;
    }

    public function setPropertyConfig(?string $propertyConfig): static
    {
        $this->propertyConfig = $propertyConfig;

        return $this;
    }

    public function isSearchable(): bool
    {
        return $this->isDatabase() || $this->isAttribute();
    }

    public function isSortable(): bool
    {
        return $this->isIdField() || $this->isAttribute() || $this->isCategory();
    }

    public function isFilterable(): bool
    {
        return $this->isRelation() || $this->isCategory();
    }

    public function isIdField(): bool {
        return $this->isDatabase() && ($this->getInternalCode() == Instance::DB_CODE_FIELD);
    }

    public function getFacetCount(): ?int
    {
        return $this->facetCount;
    }

    public function setFacetCount(?int $facetCount): static
    {
        $this->facetCount = $facetCount;

        return $this;
    }

    // coreId in the meili table, e.g. cat.status or mat
    public function getInternalIdentifier(): string
    {
        return sprintf("%s.%s", $this->getType(), $this->getInternalCode());
    }

    public function getOwner(): ?Owner
    {
        return $this->owner;
    }

    public function setOwner(?Inst $inst): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getTableNames(): ?array
    {
        return $this->tableNames;
    }

    public function setTableNames(?array $tableNames): static
    {
        $this->tableNames = $tableNames;

        return $this;
    }


}
