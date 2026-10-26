<?php

namespace App\Entity;

use App\Recipe\RecipeBook;
use App\Repository\CookidooRecipeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Cookidoo recipe read once from its public page (schema.org data), shared by every
 * user like the Respo recipes. Values are per portion, as Cookidoo gives them.
 *
 * @phpstan-import-type Ingredient from RecipeBook
 * @phpstan-import-type Recipe from RecipeBook
 */
#[ORM\Entity(repositoryClass: CookidooRecipeRepository::class)]
#[ORM\Table(name: 'cookidoo_recipes')]
class CookidooRecipe
{
    public const ID_PREFIX = 'cookidoo-';

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    /** @param list<Ingredient> $ingredients */
    public function __construct(
        #[ORM\Id, ORM\Column(length: 40)]
        private string $id,
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\Column]
        private float $kcal,
        #[ORM\Column]
        private float $proteinG,
        #[ORM\Column]
        private float $carbsG,
        #[ORM\Column]
        private float $fatG,
        /** One portion: the ingredients' grams over the number of portions (approximate). */
        #[ORM\Column]
        private float $grams,
        #[ORM\Column]
        private int $portions,
        #[ORM\Column(type: Types::JSON)]
        private array $ingredients,
        #[ORM\Column(length: 300)]
        private string $url,
    ) {
        $this->name = mb_substr(trim($name), 0, 200);
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    /** @return Recipe */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'source' => 'cookidoo', 'name' => $this->name,
            'kcal' => $this->kcal, 'protein_g' => $this->proteinG, 'carbs_g' => $this->carbsG, 'fat_g' => $this->fatG,
            'grams' => $this->grams, 'time' => '', 'plans' => 1, 'ingredients' => $this->ingredients,
        ];
    }
}
