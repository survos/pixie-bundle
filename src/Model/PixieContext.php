<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Model;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final class PixieContext
{
    public function __construct(
        public string $pixieCode,
        public Config $config,
        public EntityManagerInterface $em
    ) {}

    public function repo(string $className): ServiceEntityRepository|EntityRepository
    {
        return $this->em->getRepository($className);
    }

    public function find(string $className, int|string $id): mixed
    {
        return $this->em->getRepository($className)->find($id);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
    public function persist(mixed $entity): void
    {
        $this->em->persist($entity);
    }
}
