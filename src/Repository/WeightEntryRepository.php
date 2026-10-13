<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WeightEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WeightEntry> */
class WeightEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeightEntry::class);
    }

    public function latest(User $user): ?WeightEntry
    {
        return $this->findOneBy(['user' => $user], ['day' => 'DESC']);
    }

    /** @return list<WeightEntry> oldest first */
    public function since(User $user, string $from): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.user = :user AND w.day >= :from')
            ->setParameter('user', $user)->setParameter('from', $from)
            ->orderBy('w.day', 'ASC')->getQuery()->getResult();
    }
}
