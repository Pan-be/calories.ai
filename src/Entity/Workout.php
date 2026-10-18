<?php

namespace App\Entity;

use App\Repository\WorkoutRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One training on a given local day, as the user describes it, or a rest day. A day can
 * have several trainings (e.g. yoga in the morning, gym in the evening); a rest day is a
 * single entry with nothing else that day. Kept for context (meal suggestions now,
 * adaptive goals later), not for burned kcal.
 */
#[ORM\Entity(repositoryClass: WorkoutRepository::class)]
#[ORM\Table(name: 'workouts')]
#[ORM\Index(columns: ['user_id', 'day'])]
class Workout
{
    public const INTENSITIES = ['light', 'moderate', 'hard'];
    public const PARTS_OF_DAY = ['morning', 'midday', 'evening'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $rest = false;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $intensity = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $partOfDay = null;

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

    public function getId(): ?int { return $this->id; }
    public function getDay(): string { return $this->day; }
    public function getPartOfDay(): ?string { return $this->partOfDay; }
    public function isRest(): bool { return $this->rest; }
    public function getDescription(): ?string { return $this->description; }

    /** A rest day has no description, intensity or part of day. */
    public function set(bool $rest, ?string $description, ?string $intensity, ?string $partOfDay = null): void
    {
        $this->rest = $rest;
        $this->description = $rest ? null : $description;
        $this->intensity = $rest ? null : $intensity;
        $this->partOfDay = $rest ? null : $partOfDay;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** How the AI prompt sees it. */
    public function summary(): string
    {
        if ($this->rest) {
            return 'dzień odpoczynku';
        }
        $intensity = ['light' => 'lekki', 'moderate' => 'średni', 'hard' => 'mocny'][$this->intensity ?? ''] ?? null;
        $part = ['morning' => 'rano', 'midday' => 'w południe', 'evening' => 'wieczorem'][$this->partOfDay ?? ''] ?? null;
        return trim(($part ? "$part: " : '') . ($this->description ?? 'trening') . ($intensity ? " (intensywność: $intensity)" : ''));
    }

    /** @return array{id:?int, day:string, rest:bool, description:?string, intensity:?string, part_of_day:?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'day' => $this->day, 'rest' => $this->rest,
            'description' => $this->description, 'intensity' => $this->intensity, 'part_of_day' => $this->partOfDay,
        ];
    }
}
