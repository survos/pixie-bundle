<?php

namespace Survos\PixieBundle\Entity\Field;

interface FieldInterface
{
    public function isRelation(): bool;
    public function isReference(): bool;
    public function isCategory(): bool;
}
