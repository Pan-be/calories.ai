<?php

namespace App\Nutrition;

use App\Entity\User;
use App\Entity\WeightEntry;
use App\Repository\WeightEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Weight log and automatic goals: a new weight re-computes the goals unless they're manual. */
final class Profiles
{
    public function __construct(
        private EntityManagerInterface $em,
        private WeightEntryRepository $weights,
        private GoalCalculator $calculator,
    ) {
    }

    public function today(User $user): string
    {
        return (new \DateTimeImmutable('now', $user->zone()))->format('Y-m-d');
    }

    public function recordWeight(User $user, string $day, float $kg): WeightEntry
    {
        $entry = $this->weights->findOneBy(['user' => $user, 'day' => $day]);
        if ($entry === null) {
            $entry = new WeightEntry($user, $day, $kg);
            $this->em->persist($entry);
        } else {
            $entry->setWeightKg($kg);
        }
        $this->em->flush();
        $this->refreshGoals($user);

        return $entry;
    }

    /** Re-compute automatic goals from the profile and latest weight; null if not possible. */
    public function refreshGoals(User $user, bool $force = false): ?array
    {
        $computed = $this->calculator->forUser(
            $user,
            $this->weights->latest($user)?->getWeightKg(),
            new \DateTimeImmutable('now', $user->zone()),
        );
        if ($computed !== null && (!$user->areGoalsManual() || $force)) {
            $user->setGoals($computed, false);
            $this->em->flush();
        }
        return $computed;
    }
}
