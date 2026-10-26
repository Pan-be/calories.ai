<?php

namespace App\Recipe;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recipes with known values per portion (the Respo diet plans), read from a JSON file
 * made by bin/respo-recipes. The file is optional: without it there are no recipes.
 * It stays out of git, because the recipes aren't ours to publish.
 *
 * @phpstan-type Ingredient array{name:string, amount:string, grams:float}
 * @phpstan-type Recipe array{id:string, name:string, kcal:float, protein_g:float, carbs_g:float, fat_g:float, grams:float, time:string, plans:int, ingredients:list<Ingredient>}
 */
final class RecipeBook
{
    private const FOLD = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

    /** @var array<string, Recipe>|null */
    private ?array $recipes = null;

    public function __construct(#[Autowire(env: 'resolve:KALORIE_RECIPES_FILE')] private string $file)
    {
    }

    /** @return Recipe|null */
    public function get(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * Recipes whose name holds every word of $query (case and Polish diacritics ignored),
     * then those where some words only match an ingredient.
     *
     * @return list<Recipe>
     */
    public function search(string $query, int $limit): array
    {
        $words = array_values(array_filter(explode(' ', self::fold($query))));
        if ($words === []) {
            return [];
        }
        $byName = $byIngredient = [];
        foreach ($this->all() as $recipe) {
            $name = self::fold($recipe['name']);
            $all = $name . ' ' . self::fold(implode(' ', array_column($recipe['ingredients'], 'name')));
            if (self::holds($name, $words)) {
                $byName[] = $recipe;
            } elseif (self::holds($all, $words)) {
                $byIngredient[] = $recipe;
            }
        }
        return array_slice([...$byName, ...$byIngredient], 0, $limit);
    }

    public function count(): int
    {
        return count($this->all());
    }

    /** @return array<string, Recipe> */
    private function all(): array
    {
        if ($this->recipes !== null) {
            return $this->recipes;
        }
        $this->recipes = [];
        if (!is_file($this->file)) {
            return $this->recipes;
        }
        $data = json_decode((string) file_get_contents($this->file), true, 16, JSON_THROW_ON_ERROR);
        foreach ($data['recipes'] ?? [] as $r) {
            $this->recipes[(string) $r['id']] = [
                'id' => (string) $r['id'], 'name' => (string) $r['name'],
                'kcal' => (float) $r['kcal'], 'protein_g' => (float) $r['protein_g'],
                'carbs_g' => (float) $r['carbs_g'], 'fat_g' => (float) $r['fat_g'],
                'grams' => (float) $r['grams'], 'time' => (string) ($r['time'] ?? ''), 'plans' => (int) ($r['plans'] ?? 1),
                'ingredients' => array_map(fn ($i) => [
                    'name' => (string) $i['name'], 'amount' => (string) ($i['amount'] ?? ''), 'grams' => (float) $i['grams'],
                ], array_values($r['ingredients'] ?? [])),
            ];
        }
        return $this->recipes;
    }

    /** @param list<string> $words */
    private static function holds(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (!str_contains($text, $word)) {
                return false;
            }
        }
        return true;
    }

    private static function fold(string $text): string
    {
        return strtr(mb_strtolower(trim($text)), self::FOLD);
    }
}
