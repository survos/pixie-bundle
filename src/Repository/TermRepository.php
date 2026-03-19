<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\Term;

/**
 * @extends ServiceEntityRepository<Term>
 */
final class TermRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Term::class);
    }

    /** @return list<Term> */
    public function findBySetId(string $setId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.set = :set')
            ->setParameter('set', $setId)
            ->orderBy('t.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
