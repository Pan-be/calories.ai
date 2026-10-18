<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Workout> */
class WorkoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workout::class);
    }

    /** @return list<Workout> the day's entries: morning, midday, evening, then the ones without a time, each in the order added */
    public function forDay(User $user, string $day): array
    {
        $rank = array_flip(Workout::PARTS_OF_DAY);
        $entries = $this->findBy(['user' => $user, 'day' => $day], ['id' => 'ASC']);
        usort($entries, fn (Workout $a, Workout $b) => ($rank[$a->getPartOfDay() ?? ''] ?? 3) <=> ($rank[$b->getPartOfDay() ?? ''] ?? 3));
        return $entries;
    }

    /** @return list<Workout> oldest first */
    public function since(User $user, string $from): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.user = :user AND w.day >= :from')
            ->setParameter('user', $user)->setParameter('from', $from)
            ->orderBy('w.day', 'ASC')->addOrderBy('w.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * Descriptions the user types most often (case-insensitive), most frequent and then
     * most recent first, written as last typed: the quick picks in the app.
     *
     * @return list<string>
     */
    public function frequentDescriptions(User $user, int $limit = 8): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT description, LOWER(TRIM(description)) AS k, COUNT(*) AS n, MAX(day) AS last
             FROM workouts WHERE user_id = ? AND rest = 0 AND description IS NOT NULL AND TRIM(description) <> \'\'
             GROUP BY k ORDER BY n DESC, last DESC LIMIT ' . max(1, $limit),
            [$user->getId()],
        );
        return array_map(fn ($r) => trim($r['description']), $rows);
    }
}
