<?php


// NOT PERSISTED, maybe we can remove it.  Used during spreadsheet import


namespace Survos\PixieBundle\Entity;

use App\Entity\ProjectCoreInterface;
use App\Entity\ProjectInterface;
use App\Entity\Sheet;
use App\Entity\SheetColumn;
use App\Entity\Spreadsheet;
use App\Entity\StatsInterface;
use App\Traits\AccessTrait;
use App\Traits\ProjectCoreTrait;
use App\Traits\ProjectTrait;
use Survos\PixieBundle\Traits\StatsTrait;
use CodeInc\StripAccents\StripAccents;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\PixieBundle\Entity\Field\CategoryField;
use Survos\PixieBundle\Entity\Field\Field;
use Survos\PixieBundle\Entity\Field\FieldInterface;
use Survos\PixieBundle\Entity\Field\RelationField;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

use Doctrine\ORM\Mapping as ORM;

//#[UniqueEntity(['spreadsheet', 'projectCore', 'code'])]
// DEPRECATED — #[ORM\Entity()] # repositoryClass: FieldMapRepository::class)]
//#[ORM\UniqueConstraint(name: 'fm_spreadsheet_code', columns: ['project_id', 'core_id', 'spreadsheet_id', 'code'])]
class FieldMap # implements RouteParametersInterface, StatsInterface, ProjectCoreInterface, ProjectInterface, \Stringable
{
//    use
//        ProjectTrait,
//        StatsTrait,
//        ProzjectCoreTrait,
//        AccessTrait; // when an entity has its own ID, use AccessTrait instead of ProjectTrait

#[ORM\Column()]
#[ORM\Id()]
public int $id;

    public const PLACE_NEW = 'new';
    public const TRANSLATION_CODE = 'fieldmap';
    public static function getTranslationCode():string { return self::TRANSLATION_CODE; } // candidate for trait

    public function getProject(): Project
    {
        return $this->getSpreadsheet()->getProject();
    }


    public function setProject(Project $project): FieldMap
    {
        $this->project = $project;
        return $this;
    }
    // getType($var), so these are PHP types
    final public const FIELD_TEXT = 'textarea';

    final public const FIELD_STRING = 'string';

    final public const FIELD_INT = 'integer';

    final public const FIELD_NUMERIC = 'int';

    final public const FIELD_BOOL = 'bool';

    final public const FIELD_DATE = 'date';

    // special fields, mapped to import and used in field_config_controller.js
    final public const FIELD_TYPE_RELATIONSHIP = 'projectCoreRelationship';

    // show the relationship table
    final public const FIELD_TYPE_CUSTOM_FIELD = 'customField';

    final public const FIELD_TYPE_NESTED_LIST = 'nestedList';

    // CustomList?
    final public const FIELD_TYPE_INTRINSIC = 'databaseField';

    final public const FIELD_TYPE_PRIMARY_LABEL = 'label';

    final public const FIELD_TYPE_ADDITIONAL_TEXT = 'text';

    // customLabel?
    final public const FIELD_TYPE_IGNORED = 'ignored';

    final public const FIELD_TYPE_TYPE = '_type';

    final public const FIELD_TYPE_STATUS = '_status';

    final public const FIELD_TYPE_IMAGES = '_images';

//    #[ORM\Id]
//    #[ORM\GeneratedValue]
//    #[ORM\Column(type: 'integer')]
//    private $id;

//    #[ORM\ManyToOne(targetEntity: Field::class)]
//    #[ORM\JoinColumn(nullable: true)]
    protected ?FieldInterface $field=null;

    /**
     * @return mixed
     */
//    public function getId()
//    {
//        return $this->id;
//    }
//
//
//    /**
//     * @param mixed $id
//     * @return FieldMap
//     */
//    public function setId($id)
//    {
//        $this->id = $id;
//        return $this;
//    }

//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'fieldMaps')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;


//    #[ORM\ManyToOne(targetEntity: Core::class, inversedBy: 'fieldMaps')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Core $core; // not persisted!

//    #[ORM\ManyToOne(targetEntity: Core::class, inversedBy: 'relationshipFieldMaps')]
//    private $projectCoreRelationship;

    #[Groups(['spreadsheet'])]
//    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'The column name cannot be longer than {{ limit }} characters')]
    private $columnName;

    private $columnIdx;

//    #[Groups(['spreadsheet', 'read'])]
////    #[ORM\Column(nullable: false, type: Types::STRING, length: 255)]
//    #[Assert\Length(max: 255, maxMessage: 'The code cannot be longer than {{ limit }} characters')]
//    #[Assert\NotBlank]
//    private string $code;

//    #[Groups(['spreadsheet'])]
//    #[ORM\ManyToOne(targetEntity: CustomField::class)]
//    #[ORM\JoinColumn(nullable: true)]
//    private ?CustomField $customField = null;

    //    #[Assert\NotBlank]
    //    #[ORM\ManyToOne(targetEntity: FieldType::class)]
    //    #[ORM\JoinColumn(nullable: false, referencedColumnName: 'code')]
    //    private ?FieldType $fieldType = null;

    #[Groups(['fields.spreadsheet', 'read'])]
//    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $type = null;

    #[Groups(['spreadsheet', 'read'])]
//    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $databaseField;

    #[Groups(['spreadsheet', 'read'])]
//    #[ORM\Column(type: 'text', nullable: true)]
    private $description;

    #[Groups(['spreadsheet', 'read'])]
//    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $example;

//    #[ORM\Column(type: Types::JSON, options: [
//        'jsonb' => true,
//    ], nullable: true)]
    private $customListItems = [];

//    #[ORM\Column(type: 'boolean', nullable: true)]
    private bool $locked;

//    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['fieldMap.read'])]
    private $isIdentifier;

//    #[ORM\Column(type: Types::JSON, options: [
//        'jsonb' => true,
//    ], nullable: true)]
    private $aliases = [];

    #[Assert\NotBlank]
//    #[ORM\ManyToOne(inversedBy: 'fieldMaps', cascade: ['persist'])]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull()]
    private ?Spreadsheet $spreadsheet = null;


//    #[ORM\ManyToOne(inversedBy: 'fieldMaps')]
//    #[Groups(['fieldMap.read'])]
//    private ?CategoryField $categoryField = null;

//    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'redirectedFieldMaps', cascade: ['remove'])]
//    #[ORM\JoinColumn(onDelete: 'cascade')]
    private ?self $redirectedFieldMap = null;

//    #[ORM\OneToMany(mappedBy: 'redirectedFieldMap', targetEntity: self::class)]
    private Collection $redirectedFieldMaps;

//    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'The rule cannot be longer than {{ limit }} characters')]
    private ?string $rule = null;

//    #[ORM\ManyToOne(cascade: ['remove'])]
//    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sheet $firstSheet = null;

//    #[ORM\Column(nullable: true)]
    private ?int $firstRow = null;
    private ?int $mapOrder = null;

    /**
     * @return int|null
     */
    public function getMapOrder(): ?int
    {
        return $this->mapOrder;
    }

    /**
     * @param int|null $mapOrder
     * @return FieldMap
     */
    public function setMapOrder(?int $mapOrder): FieldMap
    {
        $this->mapOrder = $mapOrder;
        return $this;
    } // if created because of a regex map match, preserve the order for display

//    #[ORM\Column(nullable: true)]
    private ?bool $isMultiField = null;

//    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dataType = null;

//    #[ORM\Column(nullable: true)]
    private array $sheetNames = [];

//    #[ORM\Column(nullable: true)]
    private ?bool $isIgnored = null;

//    #[ORM\OneToMany(mappedBy: 'fieldMap', targetEntity: SheetColumn::class)]
    private Collection $columns;

//    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['spreadsheet'])]
    private ?string $regexConfig = null;

//    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['spreadsheet'])]
    private ?string $fieldCode = null;

    public function __construct(?Spreadsheet $spreadsheet = null, ?string $code = null, ?Core $core = null, ?Sheet $firstSheet = null)
    {
        assert(false, "@can this be removed?  It was used during importing a spreadsheet");
        $this->initId($code);
        if ($spreadsheet) {
            $spreadsheet->addFieldMap($this);
            $this->setProject($spreadsheet->getProject());
        }
        if ($firstSheet) {
            $this->setFirstSheet($firstSheet);
        }
        if ($core) {
            $core->addFieldMap($this);
            $this->setCore($core);
        }


        //        $this->initId();
        $this->redirectedFieldMaps = new ArrayCollection();
        $this->columns = new ArrayCollection();
        $this->columnIdx = 0;
    }



    /**
     * @return mixed
     */
    public function getFillCount()
    {
        return $this->fillCount;
    }

    public function getFilename(): string
    {

//        return $this->getCode() . '.csv';
        return $this->getColumnName() . '.csv';
    }

    /**
     * @param mixed $fillCount
     * @return FieldMap
     */
    public function setFillCount($fillCount)
    {
        $this->fillCount = $fillCount;
        return $this;
    }

    public function getCustomField(): ?CustomField
    {
        return $this->customField;
    }

    public function setCustomField(?CustomField $customField): FieldMap
    {
        $this->customField = $customField;
        return $this;
    }

    #[Groups(['spreadsheet'])]
    public function getFieldTypeCode()
    {
        return $this->getType(); // hack $this->getFieldType()->getCode();
    }


    /**
     * @return mixed
     */
    #[Groups(['spreadsheet'])]
    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDatabaseField()
    {
        return $this->databaseField;
    }

    /**
     * @param mixed $databaseField
     * @return FieldMap
     */
    public function setDatabaseField(string $databaseField)
    {

//        assert(false, "use setField instead");
//        assert(!str_contains($databaseField, '.'), "no .!");
        if ($databaseField) {
            assert(in_array($databaseField, Instance::DB_FIELDS), "$databaseField is not a valid database field");
            $this->databaseField = $databaseField;
        }
        return $this;
    }

    public function getColumnName(): ?string
    {
        return $this->columnName;
    }

    public function getLabel(): ?string
    {
        return $this->getColumnName();
    }

    public function setColumnName(?string $columnName): self
    {
        $this->columnName = $columnName;
        if (! isset($this->code)) {
            $slug = self::slugify($columnName);
            $this->setCode($slug);
        }
        assert(!str_contains($columnName, 'id:id'));
        return $this;
    }

    static public function isValidKey(string $key): bool
    {
        return !str_starts_with($key, '_');
    }

    public function isInternalFieldMap(): bool {
        return str_starts_with($this->getCode(), '_');
    }


    public static function slugify($columnName, $separator = '_', int $max = 255)
    {
        if ($columnName == '#') {
            return 'c';
        }
        if (empty($columnName)) {
            return null;
        }
        $x =
            (new AsciiSlugger())->slug(strtolower(StripAccents::strip(trim($columnName) ?: '')) ?: 'ignored', $separator)->toString();
        return substr($x, 0, $max);
    }

    public function getColumnIdx(): ?int
    {
        return $this->columnIdx;
    }

    public function setColumnIdx(int $columnIdx): self
    {
        $this->columnIdx = $columnIdx;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getCodeWithoutCore(): ?string
    {
        return $this->getCode();
        [$coreCode, $code] = explode('.', $this->getCode());
        return $code;
    }

    public function isExportable(): bool
    {
        if (str_starts_with($this->getCode(), '_')) {
            return false;
        }
        return true;
    }

    public function setCode(string $code): self
    {
//        if ($this->getType() == self::IN)
//        assert(in_array($dbField, Instance::DB_FIELDS), "$dbField is nnot a valid database field");

        //        assert(!str_contains($code, '.') || $this->getRelationField(), "$code contains . and is not a RelationField");
        $this->code = $code;
        if ($code == 'code') {
//            assert($code <> 'code', "setting fm code == $code");
        }

        return $this;
    }


    public function isListable(): bool
    {
        // unique and fully populated
        if (count($this->getValueCounts()) == $this->fillCount) {
            return false;
        }
//        if (in_array($this->getFieldType()->getName(), [FieldType::AC_TEXT, FieldType::AC_NUMBER])) {
//            return false;
//        }
        return true;
    }

//
//    #[Groups(['spreadsheet'])]
//    public function getProjectCoreRelationship(): ?Core
//    {
//        return $this->projectCoreRelationship;
//    }
//
//    public function setProjectCoreRelationship(?Core $relationship): self
//    {
//        $this->projectCoreRelationship = $relationship;
//
//        return $this;
//    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPopularValues($max = 3): array
    {
        return array_slice($this->getValueCounts(true), 0, $max);
    }

    public function getExample(): ?string
    {
        return $this->example;
    }

    public function setExample(?string $example): self
    {
        $this->example = $example;

        return $this;
    }

    public function getCustomListItems(): ?array
    {
        return $this->customListItems;
    }

    public function setCustomListItems(?array $customListItems): self
    {
        $this->customListItems = $customListItems;

        return $this;
    }

    #[Groups(['spreadsheet'])]
    public function getList(): string
    {
        return sprintf('@' . $this->getCode());
    }

    public function getRelationField(): FieldInterface|RelationField|null
    {
        return $this->getField()?->isRelation() ? $this->getField(): null;
    }

    public function getCategoryField(): FieldInterface|CategoryField|null
    {
        return $this->getField()?->isCategory() ? $this->getField(): null;
    }

    public function isRelationColumn(): bool
    {
        return $this->getType() == Field::TYPE_RELATION;
    }

    public function setRelationField(?RelationField $relationField): self
    {
        $this->field = $relationField;
        return $this;
    }


    public function getUniqueIdentifiers(): array
    {
        return $this->getSpreadsheet()->getRP([
            'fieldMapId' => $this->getCode(),
        ]);
    }


    public function getCore(): Core
    {
        return $this->getSpreadsheet()->getCore();
    }

    public function setCore(Core $core): FieldMap
    {
        $this->core = $core;
        return $this;
    }

    public function getLocked(): ?bool
    {
        return $this->locked;
    }

    public function setLocked(?bool $locked): self
    {
        $this->locked = $locked;

        return $this;
    }

    public function lock(): self
    {
        return $this->setLocked(true);
    }

    public function unlock(): self
    {
        return $this->setLocked(false);
    }

    public function getIsIdentifier(): ?bool
    {
        return $this->isIdentifier;
    }

    public function setIsIdentifier(?bool $isIdentifier): self
    {
        $this->isIdentifier = $isIdentifier;

        return $this;
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

    public function getFirstAlias(): ?string
    {
        return ($this->getAliases() && count($this->getAliases())) ? $this->getAliases()[0]: null;
    }

#[Groups(['fieldMap.read'])]
    public function getAliasesAsString()
    {
        return join(',', $this->getAliases());
    }

    public function addAlias($columnName)
    {
        if (! in_array($columnName, $this->aliases)) {
            array_push($this->aliases, $columnName);
        }
        return $this;
    }

    public function getSpreadsheet(): ?Spreadsheet
    {
        return $this->spreadsheet;
    }

    public function setSpreadsheet(?Spreadsheet $spreadsheet): self
    {
        $this->spreadsheet = $spreadsheet;

        return $this;
    }

    public function __toString()
    {
        return $this->getCode();
    }


    public function getCategoryType(): ?CategoryField
    {

        return $this->field;
    }



    public function getRedirectedFieldMap(): ?self
    {
        return $this->redirectedFieldMap;
    }

    public function setRedirectedFieldMap(?self $redirectedFieldMap): self
    {
        $this->redirectedFieldMap = $redirectedFieldMap;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getRedirectedFieldMaps(): Collection
    {
        return $this->redirectedFieldMaps;
    }

    public function addRedirectedFieldMap(self $redirectedFieldMap): self
    {
        if (! $this->redirectedFieldMaps->contains($redirectedFieldMap)) {
            $this->redirectedFieldMaps->add($redirectedFieldMap);
            $redirectedFieldMap->setRedirectedFieldMap($this);
        }

        return $this;
    }

    public function removeRedirectedFieldMap(self $redirectedFieldMap): self
    {
        if ($this->redirectedFieldMaps->removeElement($redirectedFieldMap)) {
            // set the owning side to null (unless already changed)
            if ($redirectedFieldMap->getRedirectedFieldMap() === $this) {
                $redirectedFieldMap->setRedirectedFieldMap(null);
            }
        }

        return $this;
    }

    public function getRule(): ?string
    {
        return $this->rule;
    }

    public function setRule(?string $rule): self
    {
        $this->rule = $rule;

        return $this;
    }

    public function getFirstSheet(): ?Sheet
    {
        return $this->firstSheet;
    }

    public function setFirstSheet(?Sheet $firstSheet): self
    {
        $this->firstSheet = $firstSheet;

        return $this;
    }

    public function getFirstRow(): ?int
    {
        return $this->firstRow;
    }

    public function setFirstRow(?int $firstRow): self
    {
        $this->firstRow = $firstRow;

        return $this;
    }

    public function isIsMultiField(): ?bool
    {
        return $this->isMultiField;
    }

    public function setIsMultiField(?bool $isMultiField): self
    {
        $this->isMultiField = $isMultiField;

        return $this;
    }


    public function getField(): FieldInterface|Field|null
    {
        return $this->field;
    }

    public function setField(FieldInterface $field): self
    {
        $this->field = $field;
        return $this;
    }

    public function getSheetNames(): array
    {
        return $this->sheetNames ?? [];
    }

    public function setSheetNames(?array $sheetNames): self
    {
        $this->sheetNames = $sheetNames;

        return $this;
    }

    public function addSheetName(string $sheetName)
    {
        if (!in_array($sheetName, $this->getSheetNames())) {
            $this->sheetNames[] = $sheetName;
        }
        return $this;
    }

    public function isIsIgnored(): ?bool
    {
        return $this->isIgnored;
    }

    public function setIsIgnored(?bool $isIgnored): self
    {
        $this->isIgnored = $isIgnored;

        return $this;
    }

    /**
     * @return Collection<int, SheetColumn>
     */
    public function getColumns(): Collection
    {
        return $this->columns;
    }

    public function addColumn(SheetColumn $column): self
    {
        if (!$this->columns->contains($column)) {
            $this->columns->add($column);
            $column->setFieldMap($this);
        }

        return $this;
    }

    public function removeColumn(SheetColumn $column): self
    {
        if ($this->columns->removeElement($column)) {
            // set the owning side to null (unless already changed)
            if ($column->getFieldMap() === $this) {
                $column->setFieldMap(null);
            }
        }

        return $this;
    }

    public function getRegexConfig(): ?string
    {
        return $this->regexConfig;
    }

    public function setRegexConfig(?string $regexConfig): self
    {
        $this->regexConfig = $regexConfig;

        return $this;
    }

    public function getFieldCode(): ?string
    {
        return $this->fieldCode;
    }

    public function setFieldCode(?string $fieldCode): self
    {
        $this->fieldCode = $fieldCode;

        return $this;
    }

    public function getSpreadsheetType(): string
    {
        return $this->getSpreadsheet()->getType();
    }



}
