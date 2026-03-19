<?php

namespace Survos\PixieBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\InstanceInterface;
use Survos\PixieBundle\Entity\Reference;

/**
 * @method Reference|null find($id, $lockMode = null, $lockVersion = null)
 * @method Reference|null findOneBy(array $criteria, array $orderBy = null)
 * @method Reference[]    findAll()
 * @method Reference[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ReferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reference::class);
    }

    public function setInstance(Reference $reference): void
    {
        if (! $reference->getInstance()) {
            $instance = $this->getEntityManager()->find($reference->getInstanceClass(), $reference->getInstanceUuid());
            $reference->setInstance($instance);
        }
    }

    public function getInstance(Reference $reference): InstanceInterface
    {
        $this->setInstance($reference);
        return $reference->getInstance();
    }

    public function getReferences(InstanceInterface $instance): iterable
    {
        $references = $this->getEntityManager()->getRepository(Reference::class)->findBy([
            'instanceUuid' => $instance->getId(),
        ]);
        return $references;
    }

    // bulk lookup.  group by class and then find all.
    public function setInstances(iterable $references)
    {
    }

    // /**
    //  * @return Reference[] Returns an array of Reference objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Reference
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
