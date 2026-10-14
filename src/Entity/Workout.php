<?php

namespace App\Entity;

use App\Repository\WorkoutRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Training on a given local day, as the user describes it, or a rest day; one entry per
 * day. Kept for context (meal suggestions now, adaptive goals later), not for burned kcal.
 */
#[ORM\Entity(repositoryClass: WorkoutRepository::class)]
#[ORM\Table(name: 'workouts')]
#[ORM\UniqueConstraint(columns: ['user_id', 'day'])]
class Workout
{
    public const INTENSITIES = ['light', 'moderate', 'hard'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $rest = false;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $intensity = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 10)]
        private string $day,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDay(): string { return $this->day; }
    public function isRest(): bool { return $this->rest; }
    public function getDescription(): ?string { return $this->description; }

    /** A rest day has no description or intensity. */
    public function set(bool $rest, ?string $description, ?string $intensity): void
    {
        $this->rest = $rest;
        $this->description = $rest ? null : $description;
        $this->intensity = $rest ? null : $intensity;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** How the AI prompt sees it. */
    public function summary(): string
    {
        if ($this->rest) {
            return 'dzień odpoczynku';
        }
        $intensity = ['light' => 'lekki', 'moderate' => 'średni', 'hard' => 'mocny'][$this->intensity] ?? null;
        return trim(($this->description ?? 'trening') . ($intensity ? " (intensywność: $intensity)" : ''));
    }

    /** @return array{day:string, rest:bool, description:?string, intensity:?string} */
    public function toArray(): array
    {
        return ['day' => $this->day, 'rest' => $this->rest, 'description' => $this->description, 'intensity' => $this->intensity];
    }
}
