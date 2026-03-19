<?php

namespace Survos\PixieBundle\Repository;

use ApiPlatform\Doctrine\Orm\Paginator;
use App\Serializer\InstanceSerializer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\Field\RelationField;
use Survos\PixieBundle\Entity\Instance;
use Survos\PixieBundle\Entity\InstanceInterface;
use Survos\PixieBundle\Entity\Relation;

/**
 * @method Relation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Relation|null findOneBy(array $criteria, array $orderBy = null)
 * @method Relation[]    findAll()
 * @method Relation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RelationRepository extends ServiceEntityRepository
{
    public const ITEMS_PER_PAGE = 10;

    public function __construct(
        ManagerRegistry $registry,
    )
    {
        parent::__construct($registry, Relation::class);
    }

    public function findCountsByRelationField(RelationField $relationField, string $locale): array
    {

        $code = $relationField->getCode();
        $projectCore = $relationField->getCore();
        $queryBuilder = $this->instanceRepository->createQueryBuilder('i');
        $queryBuilder
            ->select("JSONB_ARRAY_ELEMENTS(JSON_GET_FIELD(i.attributes, '$code')) as code, count('*') as count")
            ->andWhere('i.core = :projectCore')->setParameter('projectCore', $projectCore)
            ->addGroupBy('code')//                ->orderBy('language')
        ;
        $query = $queryBuilder->getQuery();

        $results = $query->getArrayResult();
        $results = array_filter($results, fn($result) => $result['code']);
//        array_walk($results, fn($x) => $x['code'] = trim($x['code'], '"'));
        foreach ($results as $idx => $result) {
            $results[$idx]['code'] = trim($result['code'], '"');
        }
//        dd(json_encode($results));

        return $results;

        return $arrayResults;

            dd($arrayResults);




            $countQuery = $this->createQueryBuilder('r')
            ->join('r.rightInstance', 'ri')
            ->join('r.relationField', 'field')
            ->andWhere('r.relationField = :field')
            ->setParameter('field', $relationField)
            ->select('count(r) as count, ri.code as label, ri.code as code')
            ->groupBy('ri.code');

        return $countQuery->getQuery()
//            ->setHint(
//                TranslatableListener::HINT_TRANSLATABLE_LOCALE,
//                $locale
//            )
//            ->setHint(
//                Query::HINT_CUSTOM_OUTPUT_WALKER,
//                TranslationWalker::class
//            )
            ->getResult();
        foreach ($countQuery->getQuery()->getResult() as $value) {
            dd($value);
        }
        dd($countQuery->getQuery()->getArrayResult());
    }

    public function findCountsByReverseRelationField(RelationField $relationField): array
    {
        $countQuery = $this->createQueryBuilder('r')
            ->join('r.leftInstance', 'ri') // r is for related, not right
            ->join('r.relationField', 'field')
            ->andWhere('r.relationField = :field')
            ->setParameter('field', $relationField)
            ->select('count(r) as count, ri.label as label, ri.code as code')
            ->groupBy('ri.label, ri.code');

        return $countQuery->getQuery()->getResult();
        foreach ($countQuery->getQuery()->getResult() as $value) {
            dd($value);
        }
        dd($countQuery->getQuery()->getArrayResult());
    }


    public function searchCriteria(string $searchString): Criteria
    {
        return Criteria::create()
            ->where(Criteria::expr()->contains('code', $searchString))
            ->orWhere(Criteria::expr()->contains('rightInstance.label', $searchString))
        ;
    }

    public function relationSearchQuery(
        RelationField $relationField,
                      $filter = null,
                      $firstResult = 0,
                      $itemsPerPage = self::ITEMS_PER_PAGE
    ): Paginator {
        $qb = $this->createQueryBuilder('c');
        $qb->where('c.RelationField=:RelationField')
            ->setParameter('RelationField', $relationField);

        if ($filter != null) {
            //            $qb
            //                ->where("c.code LIKE :filter")
            //                ->setParameter('filter', '%' . $filter.'%');
            $qb->addCriteria($this->searchCriteria($filter));
            //            $qb->andWhere('m.code LIKE :filter');
            //            $qb->orWhere('m.label LIKE :filter');
            //            $qb->setParameter('filter', '%' . $filter.'%');
        }

        $qb->orderBy('c.label');

        $criteria = Criteria::create()
            ->setFirstResult($firstResult)
            ->setMaxResults($itemsPerPage);

        $qb->addCriteria($criteria);

        $doctrinePaginator = new \Doctrine\ORM\Tools\Pagination\Paginator($qb);
        $paginator = new Paginator($doctrinePaginator);

        return $paginator;
    }

    public function setRightInstance(Relation $relation): void
    {
        if (! $relation->getRightInstance()) {
            $instance = $this->getEntityManager()->find(
                Instance::class,
                //                $relation->getRelationField()->getRightCore()->getEntityClass(),
                $relation->getRightInstanceId()
            );
            assert($instance, sprintf("invalid %s instance id for %s", Instance::class, $relation->getRightInstanceId()));
            $relation->setRightInstance($instance);
        }
    }

    public function setLeftInstance(Relation $relation): void
    {
        if (! $relation->getLeftInstance()) {
            $instance = $this->getEntityManager()->find(
                Instance::class,
                //                $relation->getRelationField()->getLeftCore()->getEntityClass(),
                $relation->getLeftInstanceId()
            );
            $relation->setLeftInstance($instance);
        }
    }

    /**
     * @return array<string, array>
     */
    public function getRelationsGroupedByLeftType(InstanceInterface $instance): array
    {
        $relationsByType = [];
        foreach ($this->getRelations($instance) as $relation) {
            $rt = $relation->getRelationField();
            // same as SerializerInterface::getRelationFieldName

            $relationsByType[InstanceSerializer::getRelationFieldName($rt)][] = '@' . $rt->getRightCore() . '.' . $relation->getRightCode();
        }
        return $relationsByType;
    }

    /**
     * @return array|Relation[]
     */
    public function getRelations(InstanceInterface $instance): array
    {
        // ['leftInstanceId'=> $instance->getId()]
        $relations = $this->getEntityManager()->getRepository(Relation::class)->findBy([
            'leftInstanceId' => $instance->getId(),
        ]);
        foreach ($relations as $relation) {
            $this->setRightInstance($relation);
            $this->setLeftInstance($relation);
            //            $relation->setLeftInstance($instance);
        }
        return $relations;
    }


    // /**
    //  * @return Relation[] Returns an array of Relation objects
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
    public function findOneBySomeField($value): ?Relation
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
