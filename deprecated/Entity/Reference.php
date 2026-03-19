<?php

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\IdInterface;
use App\Entity\ProjectCoreInterface;
use App\Entity\ProjectInterface;
use App\Repository\ReferenceRepository;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\PixieBundle\Traits\IdTrait;
use App\Traits\ProjectCoreTrait;
use App\Traits\ProjectTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\PixieBundle\Entity\Field\ReferenceField;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// DEPRECATED — #[ORM\Entity(repositoryClass: ReferenceRepository::class)]
//#[ORM\UniqueConstraint(name: "instance_id", columns: ["instance_id"])]
//#[ORM\UniqueConstraint(name: "core_instance_code", columns: ["core_id", 'instance_code'])]

class Reference implements
    RouteParametersInterface
{
    use RouteParametersTrait;
    use IdTrait;

    public function __construct(
        ?ReferenceField $referenceField = null,
        ?Instance       $instance = null
    )
    {
        if ($referenceField) {
            $referenceField->addReference($this);
        }
        $this->initId();
        // unique in proejct
        $this->instanceId = $instance->getId();
        $this->project = $instance->getProject();
        $this->instanceClass = $instance::class;
        $this->instance = $instance;

        // unique within core
        $this->instanceCode = $instance->getCode();
        //            $this->instanceCode = $instance->getProject()->isIdnoNaming() ? $instance->getIdno() : $instance->getCode22();
        if (!$this->instanceCode) {
            assert(false, "missing instanceCode");
        }
        assert($this->instanceCode);
        $this->instanceOrderIdx = 1; // the order of the references within this instance
        $this->instance->incReferenceCount();
        if ($instance::class == Instance::class) {
            $this->core = $instance->getCore();
        } else {
            $this->core = $instance->getProject()->getProjectCoreByClass($instance::class);
        }
        $this->setCode($this->instanceCode); // not unique!  should be the s3 filename
    }

    private ?string $name;

    public function setName($x)
    {
        assert(false);
    }

    public function LocalFileToS3(): string
    {
        dd($this->getLocalFilename()); // RELATIVE to ...?
    }


    public function getInstanceCode22(): string
    {
        return $this->getInstanceUuid()->toBase58();
    }

    public function getExtension(): string
    {
        return pathinfo($this->getFilename(), PATHINFO_EXTENSION);
    }

//    public function setInstance(?InstanceInterface $instance) { $this->instance = $instance; return $this; }
    // create the path from the instance.  This should always be the same as the actual filename, minus the extensions, which is guessed during upload.
    public function calculateFilename(): ?string
    {
        switch ($this->getProject()->getReferenceNamingScheme()) {
            case Project::NAMING_UUID:
                return sprintf("%s/%s/%s/%s", $this->getProjectCode(), $this->getCore()->getCoreCode(), $this->getInstanceCode22(), $this->getCode22());
            case Project::NAMING_CODE:
                // stick with UUID for everthing, even project?? $classes? not core, which s.b. class..
                return sprintf(
                    "%s/%s/%s/%s",
                    $this->getProjectCode(),
                    $this->getCore()->getCoreCode(),
                    $this->getInstanceCode(),
                    $this->getInstanceCode() . '-' . $this->getInstanceOrderIdx()
                );
            default:
                assert(false);
        }
        return null;
    }

    /**
     */
    #[ORM\Column(type: Types::STRING)]
    #[ApiProperty(identifier: false)]
    private readonly string $instanceId;

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups('main')]
    private ?string $filename = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['main', 'input'])]
    #[Assert\NotBlank]
    private $originalFilename;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups('main')]
    private ?string $mimeType = null;

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    #[ORM\Column(type: 'string', length: 255)]
    private $instanceClass;

//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'references')]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Project $project;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $instanceOrderIdx;

    #[ORM\Column(type: 'string', length: 255)]
    private $instanceCode;

    #[ORM\ManyToOne(inversedBy: 'references', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    protected Core $core;

    #[ORM\ManyToOne(inversedBy: 'refs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Instance $instance = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $s3Path = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $localFilename = null;

    #[ORM\ManyToOne(inversedBy: 'references')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull()]
    #[Groups(['write', 'config.read'])]
    private ReferenceField $referenceField;

    #[ORM\Column(nullable: true)]
    private ?bool $uploaded = null;

    public function init()
    {
        $this->instance = null;
    }

    public function getFilename(): ?string
    {
        return $this->s3Path; // ??  $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getInstanceClass(): ?string
    {
        return $this->instanceClass;
    }

    public function getInstanceOrderIdx(): ?int
    {
        return $this->instanceOrderIdx;
    }

    public function setInstanceOrderIdx(?int $instanceOrderIdx): self
    {
        $this->instanceOrderIdx = $instanceOrderIdx;

        return $this;
    }

    public function getInstanceCode(): ?string
    {
        return $this->instanceCode;
    }

    //    public function setInstanceCode(string $code): self
    //    {
    //        assert(!preg_match('{(/| )}', $code),  'bad instance code ' . $code);
    //        $this->instanceCode = $code;
    //        return $this;
    //    }
    public function getUniqueIdentifiers(): array
    {
        // use filename?
        return $this->getInstanceRp([
            'instanceId' => $this->getInstanceId(),
            'referenceId' => $this->getId(),
        ]);
    }

    // shortcuts, since we don't actually load the instance with the references, but we have the data.
    public function getInstanceRp(array $addlParams = [])
    {
        return $this->getCore()->getRP(array_merge($addlParams, [
            //            'shortClass' => $this->getInstanceShortClass(),
            //            'coreId' => $this->getInstance()->getProjectCore()->getCoreCode(),
            'instanceId' => $this->getInstanceId(),
        ]));
    }

    public function getInstanceShortClass(): string
    {
        return (new \ReflectionClass($this->getInstanceClass()))->getShortName();
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

    public function getS3Path(): ?string
    {
        return $this->s3Path;
    }

    public function setS3Path(?string $s3Path): self
    {
        $this->s3Path = $s3Path;

        return $this;
    }

    public function getLocalFilename(): ?string
    {
        return $this->localFilename;
    }

    public function setLocalFilename(?string $localFilename): self
    {
        $this->localFilename = $localFilename;

        return $this;
    }

    public function getReferenceField(): ?ReferenceField
    {
        return $this->referenceField;
    }

    public function setReferenceField(?ReferenceField $referenceField): self
    {
        $this->referenceField = $referenceField;

        return $this;
    }

    public function isUploaded(): ?bool
    {
        return $this->uploaded;
    }

    public function setUploaded(?bool $uploaded): self
    {
        $this->uploaded = $uploaded;

        return $this;
    }
}
