<?php

namespace App\Repository;

use App\Entity\Meal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Meal> */
class MealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Meal::class);
    }

    /** @return list<Meal> */
    public function forDay(User $user, string $day): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('i')->leftJoin('m.items', 'i')
            ->where('m.user = :user AND m.day = :day')
            ->setParameter('user', $user)->setParameter('day', $day)
            ->orderBy('m.eatenAt', 'ASC')->addOrderBy('i.position', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Sum per local day for days in [$from, $to] that have meals.
     *
     * @return array<string, array{kcal:float, protein_g:float, carbs_g:float, fat_g:float, meals:int}>
     */
    public function dailyTotals(User $user, string $from, string $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT m.day, COUNT(DISTINCT m.id) AS meals, COALESCE(SUM(i.kcal), 0) AS kcal,
                    COALESCE(SUM(i.protein_g), 0) AS protein_g, COALESCE(SUM(i.carbs_g), 0) AS carbs_g, COALESCE(SUM(i.fat_g), 0) AS fat_g
             FROM meals m LEFT JOIN meal_items i ON i.meal_id = m.id
             WHERE m.user_id = ? AND m.day BETWEEN ? AND ?
             GROUP BY m.day ORDER BY m.day',
            [$user->getId(), $from, $to],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['day']] = [
                'kcal' => round((float) $row['kcal'], 1), 'protein_g' => round((float) $row['protein_g'], 1),
                'carbs_g' => round((float) $row['carbs_g'], 1), 'fat_g' => round((float) $row['fat_g'], 1),
                'meals' => (int) $row['meals'],
            ];
        }
        return $out;
    }

    /**
     * The user's usual meals since $from: grouped by name (case-insensitive), most often
     * logged first, with average totals per serving. Feeds meal suggestions.
     *
     * @return list<array{name:string, times:int, kcal:float, protein_g:float, carbs_g:float, fat_g:float}>
     */
    public function frequent(User $user, string $from, int $limit = 12): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT name, COUNT(*) AS times, MAX(day) AS last, AVG(kcal) AS kcal, AVG(protein_g) AS protein_g, AVG(carbs_g) AS carbs_g, AVG(fat_g) AS fat_g
             FROM (SELECT m.id, m.name, m.day, LOWER(TRIM(m.name)) AS k, COALESCE(SUM(i.kcal), 0) AS kcal,
                          COALESCE(SUM(i.protein_g), 0) AS protein_g, COALESCE(SUM(i.carbs_g), 0) AS carbs_g, COALESCE(SUM(i.fat_g), 0) AS fat_g
                   FROM meals m LEFT JOIN meal_items i ON i.meal_id = m.id
                   WHERE m.user_id = ? AND m.day >= ? GROUP BY m.id)
             GROUP BY k ORDER BY times DESC, last DESC LIMIT ' . max(1, $limit),
            [$user->getId(), $from],
        );
        return array_map(fn ($r) => [
            'name' => $r['name'], 'times' => (int) $r['times'],
            'kcal' => round((float) $r['kcal']), 'protein_g' => round((float) $r['protein_g']),
            'carbs_g' => round((float) $r['carbs_g']), 'fat_g' => round((float) $r['fat_g']),
        ], $rows);
    }
}
