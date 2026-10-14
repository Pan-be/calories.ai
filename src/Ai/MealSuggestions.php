<?php

namespace App\Ai;

final readonly class MealSuggestions
{
    /**
     * @param list<array{name:string, why:string, items:list<array>, totals:array{kcal:float, protein_g:float, carbs_g:float, fat_g:float}}> $suggestions
     */
    public function __construct(public array $suggestions, public string $notes, public string $model)
    {
    }

    /** From the model's structured output (SuggestionPrompt::SCHEMA), cleaned like MealAnalysis. */
    public static function fromOutput(array $out, string $model): self
    {
        $suggestions = [];
        foreach (is_array($out['suggestions'] ?? null) ? $out['suggestions'] : [] as $s) {
            $items = is_array($s) ? MealAnalysis::cleanItems($s['items'] ?? null, 15) : [];
            if ($items === []) {
                continue;
            }
            $totals = ['kcal' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
            foreach ($items as $item) {
                foreach ($totals as $k => $_) {
                    $totals[$k] += $item[$k];
                }
            }
            $suggestions[] = [
                'name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 200) ?: 'Propozycja',
                'why' => mb_substr(trim((string) ($s['why'] ?? '')), 0, 300),
                'items' => $items,
                'totals' => array_map(fn ($v) => round($v, 1), $totals),
            ];
            if (count($suggestions) === 3) {
                break;
            }
        }
        return new self($suggestions, mb_substr(trim((string) ($out['notes'] ?? '')), 0, 500), $model);
    }
}
