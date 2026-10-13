<?php

namespace App\Entity;

use App\Repository\WeightEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/** Body weight on a given local day; one entry per day, a new one replaces it. */
#[ORM\Entity(repositoryClass: WeightEntryRepository::class)]
#[ORM\Table(name: 'weight_entries')]
#[ORM\UniqueConstraint(columns: ['user_id', 'day'])]
class WeightEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 10)]
        private string $day,
        #[ORM\Column]
        private float $weightKg,
    ) {
    }

    public function getDay(): string { return $this->day; }
    public function getWeightKg(): float { return $this->weightKg; }
    public function setWeightKg(float $kg): void { $this->weightKg = $kg; }

    /** @return array{day:string, weight_kg:float} */
    public function toArray(): array
    {
        return ['day' => $this->day, 'weight_kg' => $this->weightKg];
    }
}
