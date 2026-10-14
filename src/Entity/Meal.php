<?php

namespace App\Entity;

use App\Repository\MealRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One logged meal: its foods (MealItem) and what the analysis said about it. Totals are
 * always the sum of the items, never stored separately.
 */
#[ORM\Entity(repositoryClass: MealRepository::class)]
#[ORM\Table(name: 'meals')]
#[ORM\Index(columns: ['user_id', 'day'])]
class Meal
{
    public const SOURCES = ['photo', 'text', 'manual', 'suggestion'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** When it was eaten (UTC). */
    #[ORM\Column]
    private \DateTimeImmutable $eatenAt;

    /** The user's local calendar day of $eatenAt, Y-m-d: what the day view groups by. */
    #[ORM\Column(length: 10)]
    private string $day;

    #[ORM\Column(length: 200)]
    private string $name = '';

    #[ORM\Column(length: 10)]
    private string $source;

    /** Photo file id in PhotoStore, if the meal came from a photo. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $photo = null;

    /** What the user typed: the description, or a note sent with the photo. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?int $healthScore = null;

    #[ORM\Column(nullable: true)]
    private ?float $confidence = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $aiModel = null;

    /** The last raw analysis, kept for "fix it" follow-ups and debugging. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $analysis = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, MealItem> */
    #[ORM\OneToMany(targetEntity: MealItem::class, mappedBy: 'meal', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    public function __construct(User $user, string $source, \DateTimeImmutable $eatenAt)
    {
        $this->user = $user;
        $this->source = $source;
        $this->items = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->setEatenAt($eatenAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEatenAt(): \DateTimeImmutable { return $this->eatenAt; }
    public function getDay(): string { return $this->day; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = mb_substr(trim($name), 0, 200); }
    public function getSource(): string { return $this->source; }
    public function getPhoto(): ?string { return $this->photo; }
    public function setPhoto(?string $photo): void { $this->photo = $photo; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $text): void { $this->description = $text; }
    public function getHealthScore(): ?int { return $this->healthScore; }
    public function getConfidence(): ?float { return $this->confidence; }
    public function getNotes(): ?string { return $this->notes; }
    public function getAiModel(): ?string { return $this->aiModel; }
    public function getAnalysis(): ?array { return $this->analysis; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setEatenAt(\DateTimeImmutable $at): void
    {
        $this->eatenAt = $at->setTimezone(new \DateTimeZone('UTC'));
        $this->day = $at->setTimezone($this->user->zone())->format('Y-m-d');
    }

    public function setAnalysisInfo(?int $healthScore, ?float $confidence, ?string $notes, ?string $model, ?array $raw): void
    {
        $this->healthScore = $healthScore;
        $this->confidence = $confidence;
        $this->notes = $notes;
        $this->aiModel = $model;
        $this->analysis = $raw;
    }

    /** @return list<MealItem> */
    public function getItems(): array { return $this->items->getValues(); }

    /** @param list<MealItem> $items */
    public function replaceItems(array $items): void
    {
        $this->items->clear();
        foreach (array_values($items) as $i => $item) {
            $item->attach($this, $i);
            $this->items->add($item);
        }
    }

    /** @return array{kcal:float, protein_g:float, carbs_g:float, fat_g:float} */
    public function totals(): array
    {
        $sum = ['kcal' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
        foreach ($this->items as $item) {
            $sum['kcal'] += $item->getKcal();
            $sum['protein_g'] += $item->getProteinG();
            $sum['carbs_g'] += $item->getCarbsG();
            $sum['fat_g'] += $item->getFatG();
        }
        return $sum;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
