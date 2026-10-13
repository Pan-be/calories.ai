<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One food in a meal, with its own portion and nutrition values. */
#[ORM\Entity]
#[ORM\Table(name: 'meal_items')]
class MealItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Meal $meal = null;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\Column(length: 100)]
        private string $portion,
        #[ORM\Column]
        private float $grams,
        #[ORM\Column]
        private float $kcal,
        #[ORM\Column]
        private float $proteinG,
        #[ORM\Column]
        private float $carbsG,
        #[ORM\Column]
        private float $fatG,
    ) {
        $this->name = mb_substr(trim($name), 0, 200);
        $this->portion = mb_substr(trim($portion), 0, 100);
    }

    /** @param array{name:string, portion?:string, grams:float|int, kcal:float|int, protein_g:float|int, carbs_g:float|int, fat_g:float|int} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['name'],
            (string) ($data['portion'] ?? ''),
            round(max(0, (float) $data['grams']), 1),
            round(max(0, (float) $data['kcal']), 1),
            round(max(0, (float) $data['protein_g']), 1),
            round(max(0, (float) $data['carbs_g']), 1),
            round(max(0, (float) $data['fat_g']), 1),
        );
    }

    public function attach(Meal $meal, int $position): void
    {
        $this->meal = $meal;
        $this->position = $position;
    }

    public function getName(): string { return $this->name; }
    public function getPortion(): string { return $this->portion; }
    public function getGrams(): float { return $this->grams; }
    public function getKcal(): float { return $this->kcal; }
    public function getProteinG(): float { return $this->proteinG; }
    public function getCarbsG(): float { return $this->carbsG; }
    public function getFatG(): float { return $this->fatG; }

    /** @return array{name:string, portion:string, grams:float, kcal:float, protein_g:float, carbs_g:float, fat_g:float} */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'portion' => $this->portion, 'grams' => $this->grams, 'kcal' => $this->kcal,
            'protein_g' => $this->proteinG, 'carbs_g' => $this->carbsG, 'fat_g' => $this->fatG,
        ];
    }
}
