<?php

namespace App\Repository;

use App\Entity\ScaleMeasurement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ScaleMeasurement> */
class ScaleMeasurementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScaleMeasurement::class);
    }

    /** @return list<ScaleMeasurement> oldest first */
    public function since(User $user, string $from): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user AND m.day >= :from')
            ->setParameter('user', $user)->setParameter('from', $from)
            ->orderBy('m.measuredAt', 'ASC')->addOrderBy('m.id', 'ASC')->getQuery()->getResult();
    }

    public function latestOnDay(User $user, string $day): ?ScaleMeasurement
    {
        return $this->findOneBy(['user' => $user, 'day' => $day], ['measuredAt' => 'DESC', 'id' => 'DESC']);
    }
}
