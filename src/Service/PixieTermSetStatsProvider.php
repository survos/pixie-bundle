<?php
declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Dashboard-friendly TermSet statistics for a Pixie database.
 *
 * Assumptions (adjust class names/fields to your actual entities):
 * - TermSet entity: App\Entity\TermSet
 * - Term entity:    App\Entity\Term
 * - Term has a ManyToOne to TermSet named "termSet"
 * - Term has a human label field named "label" (or "term")
 * - Term may optionally have a numeric "count" or "weight" field for ranking
 */
final class PixieTermSetStatsProvider
{
    public function __construct(
        private readonly EntityManagerInterface $pixieEm, // bind this to your pixie entity manager
    ) {
    }

    /**
     * @return array{
     *   totalTermSets:int,
     *   byTermSet:list<array{
     *     id:int|string,
     *     code:string,
     *     termCount:int,
     *     topTerms:list<array{label:string, score:float|int|null}>
     *   }>
     * }
     */
    public function getStats(int $topN = 8): array
    {
        // Change these if your namespaces differ
        $termSetClass = \App\Entity\TermSet::class;
        $termClass    = \App\Entity\Term::class;

        // Total termsets
        $totalTermSets = (int) $this->pixieEm->createQueryBuilder()
            ->select('COUNT(ts.id)')
            ->from($termSetClass, 'ts')
            ->getQuery()
            ->getSingleScalarResult();

        // Count terms per termset
        // Note: assumes Term.termSet association exists.
        $rows = $this->pixieEm->createQueryBuilder()
            ->select('ts.id AS id, ts.code AS code, COUNT(t.id) AS termCount')
            ->from($termSetClass, 'ts')
            ->leftJoin($termClass, 't', 'WITH', 't.termSet = ts')
            ->groupBy('ts.id, ts.code')
            ->orderBy('ts.code', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // For top terms, try to detect a good ordering field.
        // If neither exists, we just order by id desc (stable enough for a dashboard).
        $termMeta = $this->pixieEm->getClassMetadata($termClass);
        $orderField = null;
        if ($termMeta->hasField('count')) {
            $orderField = 'count';
        } elseif ($termMeta->hasField('weight')) {
            $orderField = 'weight';
        }

        $labelField = null;
        if ($termMeta->hasField('label')) {
            $labelField = 'label';
        } elseif ($termMeta->hasField('term')) {
            $labelField = 'term';
        }

        $byTermSet = [];
        foreach ($rows as $r) {
            $tsId = $r['id'];

            $qb = $this->pixieEm->createQueryBuilder()
                ->select('t')
                ->from($termClass, 't')
                ->andWhere('t.termSet = :tsId')
                ->setParameter('tsId', $tsId)
                ->setMaxResults($topN);

            if ($orderField) {
                $qb->addOrderBy('t.' . $orderField, 'DESC');
            } else {
                $qb->addOrderBy('t.id', 'DESC');
            }

            $terms = $qb->getQuery()->getResult();

            $topTerms = [];
            foreach ($terms as $t) {
                // label
                $label = null;
                if ($labelField) {
                    $getter = 'get' . ucfirst($labelField);
                    if (method_exists($t, $getter)) {
                        $label = (string) $t->$getter();
                    }
                }
                $label ??= method_exists($t, '__toString') ? (string) $t : ('#' . (string) $t->getId());

                // score
                $score = null;
                if ($orderField) {
                    $getter = 'get' . ucfirst($orderField);
                    if (method_exists($t, $getter)) {
                        $score = $t->$getter();
                    }
                }

                $topTerms[] = [
                    'label' => $label,
                    'score' => $score,
                ];
            }

            $byTermSet[] = [
                'id' => $tsId,
                'code' => (string) ($r['code'] ?? $tsId),
                'termCount' => (int) ($r['termCount'] ?? 0),
                'topTerms' => $topTerms,
            ];
        }

        return [
            'totalTermSets' => $totalTermSets,
            'byTermSet' => $byTermSet,
        ];
    }
}
