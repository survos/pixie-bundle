<?php

// add import/export data, could also be a related class, since it's heavy to load.
namespace Survos\PixieBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class KeyValue
{
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private $data = [];

//    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
//    private $rawData = [];

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Checks if an attribute is defined.
     *
     * @return bool true if the attribute is defined, false otherwise
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }

//    public function getRawData(): ?array
//    {
//        return $this->rawData;
//    }
//
//    public function setRawData(?array $rawData): self
//    {
//        $this->rawData = $rawData;
//
//        return $this;
//    }
}
