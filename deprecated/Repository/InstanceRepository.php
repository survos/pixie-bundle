<?php

namespace Survos\PixieBundle\Repository;

use ApiPlatform\Doctrine\Orm\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Survos\CoreBundle\Traits\QueryBuilderHelperInterface;
use Survos\CoreBundle\Traits\QueryBuilderHelperTrait;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Field\AttributeField;
use Survos\PixieBundle\Entity\Field\CategoryField;
use Survos\PixieBundle\Entity\Field\DatabaseField;
use Survos\PixieBundle\Entity\Field\MeasurementField;
use Survos\PixieBundle\Entity\Field\RelationField;
use Survos\PixieBundle\Entity\Instance;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Instance>
 *
 * @method Instance|null find($id, $lockMode = null, $lockVersion = null)
 * @method Instance|null findOneBy(array $criteria, array $orderBy = null)
 * @method Instance[]    findAll()
 * @method Instance[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InstanceRepository extends ServiceEntityRepository implements QueryBuilderHelperInterface
{
    use QueryBuilderHelperTrait;
    public const ITEMS_PER_PAGE = 30;

    public function searchCriteria(string $searchString): Criteria
    {
        return Criteria::create()
            ->where(Criteria::expr()->contains('code', $searchString))
            ->orWhere(Criteria::expr()->contains('label', $searchString))
        ;
    }


    public function findCountBySearchString(string $searchString, ?Project $project): array
    {
        // @todo: security
        $queryBuilder = $this->createQueryBuilder('i');
        $field = 'label';
        if (str_starts_with($searchString, '@')) {
            $field = 'code';
            $searchString = trim($searchString, '@');
        }

        // save the criteria for exact lookup later
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('project', $project))
            ->andWhere(Criteria::expr()->contains($field, $searchString));


        $queryBuilder->select('pc.code, COUNT(pc.code) as cnt')
            ->join('i.projectCore', 'pc');
        $queryBuilder->addCriteria($criteria);

        $countQuery = $queryBuilder
            ->groupBy('pc.code')
            ->getQuery()
            ;
        $counts = $countQuery->getResult();
//        // ideally we'd do  a single query to get the unique values using HAVING
//        $uniqueInstanceQuery = $queryBuilder
//            ->select(['pc.code','i.code','i.label'])
//            ->groupBy('pc.code','i.code','i.label')
//            ->having('count(pc.code) = 1')
//            ->getQuery();
//        dd(json_encode($countQuery->getResult()),
//            json_encode($uniqueInstanceQuery->getResult()));


        $finalCounts = [];
        foreach ($counts as $count) {
            if ($count['cnt'] === 1) {
                $instance = $this->createQueryBuilder('i')
                    ->join('i.projectCore', 'pc')
                    ->addCriteria($criteria)
                    ->andWhere('pc.code = :pcCoode')
                    ->setParameter('pcCoode', $count['code'])
                    ->getQuery()
                    ->getOneOrNullResult();
                $count['iCode'] = $instance->getCode();
                $count['iLabel'] = $instance->getLabel();
                $count['instance'] = $instance;
                // get it for even faster lookup
            }
            $finalCounts[] = $count;
        }

        return $finalCounts;

        return $query->getResult();
        dd($query->getResult());


        // idea: inject the user to ProjectFilter and only filter if visitor
        $queryBuilder = $this->createQueryBuilder('i')
            ->where('i.label LIKE :q')
            ->setParameter('q', $searchString);
        if ($project) {
            $queryBuilder->andWhere('i.project = :project')
                ->setParameter('project', $project);
        }

        return Criteria::create()
            ->where(Criteria::expr()->contains('code', $searchString))
            ->orWhere(Criteria::expr()->contains('label', $searchString))
            ;
    }

    public function findCountsByCategoryField(CategoryField $categoryField): array
    {
        $code = $categoryField->getCode();
        $projectCore = $categoryField->getCore();
        $queryBuilder = $this->createQueryBuilder('i');
        $queryBuilder
            ->select("JSONB_ARRAY_ELEMENTS(JSON_GET_FIELD(i.attributes, '$code')) as code, count('*') as count")
            ->andWhere('i.core = :projectCore')->setParameter('projectCore', $projectCore)
            ->addGroupBy('code')//                ->orderBy('language')
        ;
        $query = $queryBuilder->getQuery();

//
//        $attribute = $categoryField->getCode();
//        $query = $this->createQueryBuilder('i')
//            ->select(sprintf("JSON_GET_FIELD_AS_TEXT(i.attributes, '%s') as code, count(JSON_GET_FIELD_AS_TEXT(i.attributes, '%s')) as count", $attribute, $attribute))
////            ->andWhere("JSON_GET_FIELD_AS_TEXT(i.attributes, '%s') IS NOT NULL")
//            ->groupBy('code')
////            ->setMaxResults(10)
//            ->getQuery();

        $results = $query->getArrayResult();
        $results = array_filter($results, fn($result) => $result['code']);
//        array_walk($results, fn($x) => $x['code'] = trim($x['code'], '"'));
        foreach ($results as $idx => $result) {
            $results[$idx]['code'] = trim($result['code'], '"');
        }

        return $results;

        $countQuery = $this->createQueryBuilder('i')
            ->join('i.instanceCategories', 'ic')
            ->join('ic.category','cat')
            ->andWhere('cat.categoryField = :field')
            ->setParameter('field', $categoryField)
            ->select('count(cat.id) as count, cat.code, cat.label')
//            ->select('count(cf.id) as count, cf.code as cfCode, c.label as label, c.code as code')
            ->groupBy('cat.code, cat.label');

        $counts =  $countQuery->getQuery()->getResult();
//        dd(json_encode($counts), $countQuery->getQuery()->getSQL());
        return $counts;
    }


    public function findCountsByDatabaseField(DatabaseField $field): array
    {
        return []; //
    }


    public function findCountsByAttributeField(MeasurementField|AttributeField $field): array
    {
        $attribute = $field->getCode();

        https://stackoverflow.com/questions/35416627/query-and-count-on-jsonb-column
        $query = $this->createQueryBuilder('i')
            ->select(sprintf("JSON_GET_FIELD_AS_TEXT(i.attributes, '%s') as code, count(JSON_GET_FIELD_AS_TEXT(i.attributes, '%s')) as count", $attribute, $attribute))
            ->groupBy('code')
//            ->setMaxResults(10)
            ->getQuery();

            $results = $query->getArrayResult();
//            dd($results, $results[0]);
        $x = [];
        foreach ($results as $result) {
            $code=$result['code'];
            $x[] = [
                'label' => $code,
                'code' => $code,
                'count' => $result['count']
            ];
        }
        return $x;

    }

    public function findByAttribute($attribute, $value)
    {
        $query = $this->createQueryBuilder('i')
            ->andWhere(sprintf("JSON_GET_FIELD_AS_TEXT(i.attributes, '%s') = :attrValue", $attribute))
            ->setParameter('attrValue', $value)
//            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery();

            return $query
                ->getResult();
        try {
        } catch (\Exception $exception) {
            dd($exception->getMessage(),
                $query->getSQL(),
                $query);
        }
    }


    public function findByRelationField(
        RelationField $relationField,
                      $filter = null,
                      $firstResult = 0,
                      $itemsPerPage = self::ITEMS_PER_PAGE
    ): Paginator {
        $qb = $this->createQueryBuilder('i');
        $qb->where('i.projectCore=:rightProjectCore')
            ->setParameter('rightProjectCore', $relationField->getRightCore());

        if ($filter != null) {
            //            $qb
            //                ->where("c.code LIKE :filter")
            //                ->setParameter('filter', '%' . $filter.'%');
            $qb->addCriteria($this->searchCriteria($filter));
            //            $qb->andWhere('m.code LIKE :filter');
            //            $qb->orWhere('m.label LIKE :filter');
            //            $qb->setParameter('filter', '%' . $filter.'%');
        }

        $qb->orderBy('i.label');

        $criteria = Criteria::create()
            ->setFirstResult($firstResult)
            ->setMaxResults($itemsPerPage);

        $qb->addCriteria($criteria);

        $doctrinePaginator = new \Doctrine\ORM\Tools\Pagination\Paginator($qb);
        $paginator = new Paginator($doctrinePaginator);

        return $paginator;
    }

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instance::class);
    }

    public function save(Instance $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Instance $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByInstanceId(string|Uuid $instanceId, ?Core $projectCore = null, bool $throwErrorIfNotFound = false): ?Instance
    {
        /** @var Instance $instance */
        if (Uuid::isValid($instanceId)) {
            $instance = $this->find($instanceId);
            if ($throwErrorIfNotFound) {
                assert($instance, "Missing UUID $instanceId");
            }
        } else {
            $instance = $this->findOneBy([
                'projectCore' => $projectCore,
                'code' => $instanceId,
            ]);
            if ($throwErrorIfNotFound) {
                assert($instance, "Missing $instanceId in " . $projectCore->getCoreCode());
            }
        }

        return $instance;
    }

    public function findByCore(Core $projectCore): iterable
    {
        return $this->createQueryBuilder('i')
//            ->join('i.projectCore', 'core')
            ->andWhere('i.core = :pc')
            ->setParameters([
                'pc' => $projectCore,
            ])
            ->getQuery()
            ->getResult()
        ;
    }

    // this is odd, shouldn't it be in ProjectCoreRepo?
    public function findOneByCoreAndProjectCodes($coreId, $projectId): ?Core
    {
        return $this->createQueryBuilder('i')
//            ->join('pc.core', 'core')
            ->join('pc.project', 'project')

            ->andWhere('project.code = :pid')
            ->andWhere('core.entityName = :cid')
            ->setParameters([
                'pid' => $projectId,
                'cid' => $coreId,
            ])
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }



//    /**
//     * @return Instance[] Returns an array of Instance objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('i')
//            ->andWhere('i.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('i.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Instance
//    {
//        return $this->createQueryBuilder('i')
//            ->andWhere('i.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
