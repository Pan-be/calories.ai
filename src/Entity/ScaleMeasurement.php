<?php

namespace App\Entity;

use App\Repository\ScaleMeasurementRepository;
use App\Scale\BodyComposition;
use App\Scale\ScaleFrame;
use Doctrine\ORM\Mapping as ORM;

/**
 * One weighing on the Bluetooth scale, many a day. The raw FFF3 frame is kept as sent so
 * body composition is (re)computed from it on read, see BodyComposition.
 */
#[ORM\Entity(repositoryClass: ScaleMeasurementRepository::class)]
#[ORM\Table(name: 'scale_measurements')]
#[ORM\Index(columns: ['user_id', 'day'])]
class ScaleMeasurement
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $measuredAt;

    /** The user's local calendar day of $measuredAt, Y-m-d. */
    #[ORM\Column(length: 10)]
    private string $day;

    #[ORM\Column(length: 236, nullable: true)]
    private ?string $frame = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column]
        private float $weightKg,
        \DateTimeImmutable $measuredAt,
    ) {
        $this->measuredAt = $measuredAt->setTimezone(new \DateTimeZone('UTC'));
        $this->day = $measuredAt->setTimezone($user->zone())->format('Y-m-d');
    }

    public function getId(): ?int { return $this->id; }
    public function getDay(): string { return $this->day; }
    public function getWeightKg(): float { return $this->weightKg; }
    public function setFrame(ScaleFrame $frame): void { $this->frame = $frame->hex; }

    /**
     * The body composition is computed here, on read, from the frame and the current profile.
     *
     * @return array{id:?int, measured_at:string, day:string, weight_kg:float, impedances:list<float>|null, body:array{fat_pct:float, fat_kg:float, lean_kg:float, water_pct:float}|null, body_status:string}
     */
    public function toArray(): array
    {
        $impedances = $this->frame === null ? null : ScaleFrame::parse($this->frame)->impedances;
        $composition = BodyComposition::compute($impedances, $this->weightKg, $this->user);

        return [
            'id' => $this->id,
            'measured_at' => $this->measuredAt->setTimezone($this->user->zone())->format(\DateTimeInterface::RFC3339),
            'day' => $this->day,
            'weight_kg' => $this->weightKg,
            'impedances' => $impedances,
            'body' => $composition['body'],
            'body_status' => $composition['status'],
        ];
    }
}
