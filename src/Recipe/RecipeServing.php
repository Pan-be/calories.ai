<?php

namespace App\Recipe;

use App\Http\ApiError;

/**
 * How much of a recipe was eaten, as a number of portions or as grams, and the meal item
 * and description that follow from it.
 *
 * @phpstan-import-type Recipe from RecipeBook
 */
final readonly class RecipeServing
{
    /** @param Recipe $recipe */
    private function __construct(private array $recipe, private float $factor, private string $portion)
    {
    }

    /** @param Recipe $recipe */
    public static function portions(array $recipe, float $portions): self
    {
        return new self($recipe, $portions, self::portionText($portions));
    }

    /** @param Recipe $recipe */
    public static function grams(array $recipe, float $grams): self
    {
        if ($recipe['grams'] <= 0) {
            throw ApiError::invalid('Ten przepis nie ma gramatury, podaj liczbę porcji.');
        }
        return new self($recipe, $grams / $recipe['grams'], '');
    }

    /** @return array{name:string, portion:string, grams:float, kcal:float, protein_g:float, carbs_g:float, fat_g:float} */
    public function item(): array
    {
        $r = $this->recipe;
        return [
            'name' => $r['name'], 'portion' => $this->portion, 'grams' => $r['grams'] * $this->factor,
            'kcal' => $r['kcal'] * $this->factor, 'protein_g' => $r['protein_g'] * $this->factor,
            'carbs_g' => $r['carbs_g'] * $this->factor, 'fat_g' => $r['fat_g'] * $this->factor,
        ];
    }

    /** What went into the eaten amount, so a later "Popraw z AI" knows the ingredients. */
    public function description(): string
    {
        $amount = $this->portion !== '' ? $this->portion : self::number($this->recipe['grams'] * $this->factor) . ' g';
        $parts = array_map(
            fn ($i) => $i['grams'] > 0 ? $i['name'] . ' ' . self::number($i['grams'] * $this->factor) . ' g' : trim($i['amount'] . ' ' . $i['name']),
            $this->recipe['ingredients'],
        );

        return "Przepis ({$amount}): " . implode(', ', $parts);
    }

    /** "1 porcja", "2 porcje", "5 porcji", "0,5 porcji". */
    private static function portionText(float $n): string
    {
        $word = match (true) {
            $n == 1 => 'porcja',
            floor($n) != $n => 'porcji',
            in_array((int) $n % 10, [2, 3, 4], true) && !in_array((int) $n % 100, [12, 13, 14], true) => 'porcje',
            default => 'porcji',
        };
        return self::number($n) . ' ' . $word;
    }

    private static function number(float $v): string
    {
        return str_replace('.', ',', (string) round($v, $v < 10 ? 1 : 0));
    }
}
