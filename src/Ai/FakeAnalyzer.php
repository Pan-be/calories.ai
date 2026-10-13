<?php

namespace App\Ai;

/**
 * Fixed answers without any AI, for tests and for trying the app offline
 * (KALORIE_ANALYZER=fake). A description containing "kamień" is "not food".
 */
final class FakeAnalyzer implements MealAnalyzer
{
    public function analyze(AnalysisRequest $request): MealAnalysis
    {
        $text = mb_strtolower(($request->description ?? '') . ' ' . ($request->correction ?? ''));
        if (str_contains($text, 'kamień')) {
            return MealAnalysis::fromOutput(['is_food' => false, 'meal_name' => 'Brak jedzenia', 'items' => [], 'notes' => 'To nie jest jedzenie.'], 'fake');
        }
        $items = [
            ['name' => 'Jajecznica z 2 jaj', 'portion' => '2 jajka', 'grams' => 120, 'kcal' => 200, 'protein_g' => 14, 'carbs_g' => 1, 'fat_g' => 15],
            ['name' => 'Chleb żytni', 'portion' => '1 kromka', 'grams' => 40, 'kcal' => 100, 'protein_g' => 3, 'carbs_g' => 19, 'fat_g' => 1],
        ];
        if ($request->previous !== null) {
            $items = $request->previous['items'];
            $items[] = ['name' => 'Masło', 'portion' => '1 łyżeczka', 'grams' => 5, 'kcal' => 37, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 4.1];
        }
        return MealAnalysis::fromOutput([
            'is_food' => true,
            'meal_name' => $request->photoJpeg !== null ? 'Śniadanie ze zdjęcia' : 'Śniadanie z opisu',
            'items' => $items,
            'health_score' => 7,
            'confidence' => 0.9,
            'notes' => 'Odpowiedź testowa.',
        ], 'fake');
    }
}
