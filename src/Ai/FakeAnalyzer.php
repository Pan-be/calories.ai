<?php

namespace App\Ai;

/**
 * Fixed answers without any AI, for tests and for trying the app offline
 * (KALORIE_ANALYZER=fake). A description containing "kamień" is "not food"; suggestions
 * are three fixed meals, the first one named after the user's note if there is one.
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

    public function suggest(SuggestionRequest $request): MealSuggestions
    {
        $item = fn (string $name, float $g, float $kcal, float $p, float $c, float $f) =>
            ['name' => $name, 'portion' => "{$g} g", 'grams' => $g, 'kcal' => $kcal, 'protein_g' => $p, 'carbs_g' => $c, 'fat_g' => $f];
        return MealSuggestions::fromOutput(['suggestions' => [
            ['name' => $request->note ?: 'Skyr z owocami', 'why' => 'Dużo białka, mało tłuszczu.',
                'items' => [$item('Skyr naturalny', 300, 189, 33, 12, 0), $item('Borówki', 100, 57, 1, 14, 0)]],
            ['name' => 'Kanapki z indykiem', 'why' => 'Białko i węglowodany.',
                'items' => [$item('Chleb żytni', 80, 200, 6, 38, 2), $item('Polędwica z indyka', 100, 100, 20, 2, 1)]],
            ['name' => 'Twarożek z ryżem', 'why' => 'Szybko i lekko.',
                'items' => [$item('Twaróg chudy', 200, 180, 36, 7, 1), $item('Ryż ugotowany', 150, 195, 4, 42, 0)]],
        ], 'notes' => 'Odpowiedź testowa.'], 'fake');
    }
}
