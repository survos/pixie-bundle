<?php

namespace Survos\PixieBundle\Entity;

use App\Entity\AsBarcodeInterface;
use App\Entity\IdInterface;
use App\Entity\ProjectCoreInterface;
use App\Entity\ProjectInterface;
use App\Repository\RelationRepository;
use App\Traits\ProjectCoreTrait;
use App\Traits\ProjectTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\PixieBundle\Entity\Field\RelationField;
use Survos\PixieBundle\Traits\IdTrait;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

// DEPRECATED — #[ORM\Entity(repositoryClass: RelationRepository::class)]
//#[ORM\UniqueConstraint(name: 'core_relation_code', columns: ['core_id', 'code'])]
#[UniqueEntity(['core','code'])]
//#[ORM\Index('relation_core', columns: ['core_id'])]

class Relation implements
    RouteParametersInterface, \Stringable
{
    use RouteParametersTrait;
    use IdTrait;

    #[ORM\Column(type: Types::STRING)]
    private $leftInstanceId;

    #[ORM\Column(type: Types::STRING)]
    private $rightInstanceId;

//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'relations')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    // these are the instance codes, used during import when creating the relations queue.
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $leftCode;

    #[ORM\ManyToOne(inversedBy: 'relations')]
    #[ORM\JoinColumn(name: 'relation_core', nullable: false)]
    protected Core $core;

    #[ORM\ManyToOne(inversedBy: 'relations')]
    #[ORM\JoinColumn(name: 'relation_instance', nullable: false)]
    private ?Instance $instance = null;

    //    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    //    private $rightCode;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'leftRelations')]
        #[ORM\JoinColumn(nullable: false)]
        private Instance      $leftInstance,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private string        $rightCode, // during import only, arguably shouldn't be persisted, but nice for debugging

        //        #[ORM\ManyToOne(targetEntity: Instance::class, inversedBy: 'relations')]
        //        #[ORM\JoinColumn(nullable: false)]
        //        private InstanceInterface $leftInstance,
        //        private InstanceInterface $rightInstance,
        #[ORM\ManyToOne(targetEntity: RelationField::class, inversedBy: 'relations')]
        #[ORM\JoinColumn(nullable: false)]
        private RelationField $relationField,
        #[ORM\ManyToOne(inversedBy: 'rightRelations')]
        #[ORM\JoinColumn(nullable: false)]
        private ?Instance     $rightInstance = null, // cannot persist as null, but it will be null until the right instance code is resolved.

        ?string                $code = null
    ) {
        $this->initId(code: $code);

        $this->leftInstanceId = $leftInstance->getId();
        $this->leftCode = $leftInstance->getCode();
        // really only on persist, not when queuing up
        //        assert($this->leftCode, "Left instance requires a code " . $leftInstance::class);
        if ($this->rightInstance) {
            $this->rightInstanceId = $rightInstance->getId();
        }
        //        $this->rightCode = $rightInstance->getCode();

        $this->project = $leftInstance->getProject(); // to avoid triggering the persist during import.?
        $this->project->addRelation($this);
        $this->leftInstance->addRelation($this);
        $this->leftInstance->getCore()->addRelation($this);
        $this->setCode($code ?: self::createCode($this->getLeftCode(), $this->getRightCode(), $relationField->getCode()));
        //        $leftInstance->getProject()->addRelation($this);
    }

    private ?string $name;
    public function setName($x) { assert(false); }

    public static function createCode($left, $right, $type): string
    {
        return sprintf("%s.%s.%s", substr($left, 0, 32), substr($type, 0, 32), substr($right, 0, 32));
    }

    public function getReverseCode(): string
    {
        return sprintf("%s.%s.%s", $this->getRightCode(), $this->getRelationField()->getReverseCode(), $this->getLeftCode());
    }

    //    public function setRightInstance(InstanceInterface $rightInstance)
//    {
//        $this->rightInstance = $rightInstance;
//    }
//    public function getRightInstance(): ?InstanceInterface
//    {
//        return $this->rightInstance ?? null;
//    }
//    public function setLeftInstance(InstanceInterface $leftInstance)
//    {
//        $this->leftInstance = $leftInstance;
//    }
//    public function getLeftInstance(): ?InstanceInterface
//    {
//        return $this->leftInstance ?? null;
//    }
    public function getRelationField(): ?RelationField
    {
        return $this->relationField;
    }

    public function setRelationField(?RelationField $relationField): self
    {
        $this->relationField = $relationField;

        return $this;
    }

    public function getLeftInstanceId()
    {
        return $this->leftInstanceId;
    }

    public function setLeftInstanceId($leftInstanceId): self
    {
        $this->leftInstanceId = $leftInstanceId;

        return $this;
    }

    public function getRightInstanceId()
    {
        return $this->rightInstanceId;
    }

    public function getRightCore(): string
    {
        return $this->getRelationField()->getRightCore();
    }

    public function setRightInstanceId($rightInstanceId): self
    {
        $this->rightInstanceId = $rightInstanceId;

        return $this;
    }


    public function getLeftCode(): ?string
    {
        return $this->leftCode;
    }

    public function setLeftCode(?string $leftCode): self
    {
        $this->leftCode = $leftCode;

        return $this;
    }

    public function getRightCode(): ?string
    {
        return $this->rightCode;
    }

    public function setRightCode(?string $rightCode): self
    {
        $this->rightCode = $rightCode;

        return $this;
    }

    public function leftRp(): array
    {
        return [
            'instanceId' => $this->getLeftInstanceId(),
        ];
    }

    public function rightRp(): array
    {
        return [
            'instanceId' => $this->getRightInstanceId(),
        ];
    }

    public function getLeftInstance(): ?Instance
    {
        return $this->leftInstance;
    }

    public function setLeftInstance(?Instance $leftInstance): self
    {
        $this->leftInstance = $leftInstance;

        return $this;
    }

    public function getRightInstance(): ?Instance
    {
        return $this->rightInstance;
    }

    public function setRightInstance(?Instance $rightInstance): self
    {
        $this->rightInstance = $rightInstance;

        return $this;
    }

    public function __toString()
    {
        return sprintf("%s %s %s", $this->getLeftCode(), $this->relationField->getCode(), $this->getRightCode());
    }

    public function getFullCode()
    {
        return sprintf("%s-%s-%s", $this->getLeftCode(), $this->relationField->getCode(), $this->getRightCode());
    }

    public function getInstance(): ?Instance
    {
        return $this->instance;
    }

    public function setInstance(?Instance $instance): self
    {
        $this->instance = $instance;

        return $this;
    }

    public function asBarcodeString(bool $includeId = false): string
    {
        // this does NOT include the left instance code, so it's not a complete relation.
        $rt = $this->getRelationField();
        // $rt->getRightCore(), removed, since it's now in the rt.code
        $data = [$rt->getLeftCore(), 'r', $rt->getCode(),   $this->getRightInstance()->getCode()];
        if ($includeId) {
            $data[] = $this->getId()->toBase58();
        }
        return join(',', $data);
    }
}
