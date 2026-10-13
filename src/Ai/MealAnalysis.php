<?php

namespace App\Ai;

final readonly class MealAnalysis
{
    /**
     * @param list<array{name:string, portion:string, grams:float, kcal:float, protein_g:float, carbs_g:float, fat_g:float}> $items
     */
    public function __construct(
        public bool $isFood,
        public string $name,
        public array $items,
        public ?int $healthScore,
        public ?float $confidence,
        public string $notes,
        public string $model,
        public array $raw,
    ) {
    }

    /**
     * From the model's structured output (MealPrompt::SCHEMA). Values are clamped and
     * cleaned rather than trusted, so a sloppy answer can't break the app.
     */
    public static function fromOutput(array $out, string $model): self
    {
        $items = [];
        foreach (is_array($out['items'] ?? null) ? $out['items'] : [] as $item) {
            if (!is_array($item) || trim((string) ($item['name'] ?? '')) === '') {
                continue;
            }
            $num = fn (string $k, float $max) => round(min($max, max(0.0, (float) ($item[$k] ?? 0))), 1);
            $items[] = [
                'name' => mb_substr(trim((string) $item['name']), 0, 200),
                'portion' => mb_substr(trim((string) ($item['portion'] ?? '')), 0, 100),
                'grams' => $num('grams', 5000),
                'kcal' => $num('kcal', 10000),
                'protein_g' => $num('protein_g', 1000),
                'carbs_g' => $num('carbs_g', 1000),
                'fat_g' => $num('fat_g', 1000),
            ];
            if (count($items) === 30) {
                break;
            }
        }
        $score = isset($out['health_score']) && is_numeric($out['health_score']) ? (int) round((float) $out['health_score']) : null;
        $confidence = isset($out['confidence']) && is_numeric($out['confidence']) ? (float) $out['confidence'] : null;

        return new self(
            isFood: (bool) ($out['is_food'] ?? true) && $items !== [],
            name: mb_substr(trim((string) ($out['meal_name'] ?? '')), 0, 200) ?: 'Posiłek',
            items: $items,
            healthScore: $score === null ? null : max(1, min(10, $score)),
            confidence: $confidence === null ? null : round(max(0.0, min(1.0, $confidence)), 2),
            notes: mb_substr(trim((string) ($out['notes'] ?? '')), 0, 1000),
            model: $model,
            raw: $out,
        );
    }
}
