<?php

namespace App\Tests\Unit;

use App\Ai\AnalysisRequest;
use App\Ai\MealAnalysis;
use App\Ai\MealPrompt;
use PHPUnit\Framework\TestCase;

final class MealAnalysisTest extends TestCase
{
    public function testCleansSloppyOutput(): void
    {
        $a = MealAnalysis::fromOutput([
            'is_food' => true,
            'meal_name' => '  Obiad ',
            'items' => [
                ['name' => 'Ziemniaki', 'portion' => '200 g', 'grams' => 200, 'kcal' => '170.04', 'protein_g' => 4, 'carbs_g' => 38, 'fat_g' => -2],
                ['name' => '   '],
                'nonsense',
                ['name' => 'Kotlet', 'grams' => 99999, 'kcal' => 400],
            ],
            'health_score' => 14,
            'confidence' => 1.7,
        ], 'claude-sonnet-5-5');

        $this->assertTrue($a->isFood);
        $this->assertSame('Obiad', $a->name);
        $this->assertCount(2, $a->items);
        $this->assertSame(170.0, $a->items[0]['kcal']);
        $this->assertSame(0.0, $a->items[0]['fat_g']);
        $this->assertSame(5000.0, $a->items[1]['grams']);
        $this->assertSame(0.0, $a->items[1]['protein_g']);
        $this->assertSame(10, $a->healthScore);
        $this->assertSame(1.0, $a->confidence);
        $this->assertSame('', $a->notes);
    }

    public function testNoItemsMeansNotFood(): void
    {
        $this->assertFalse(MealAnalysis::fromOutput(['is_food' => true, 'items' => []], 'x')->isFood);
        $this->assertSame('Posiłek', MealAnalysis::fromOutput([], 'x')->name);
    }

    public function testUserTextFencesUserInput(): void
    {
        $text = MealPrompt::userText(new AnalysisRequest(photoJpeg: 'x', description: 'bez cukru', previous: ['items' => []], correction: 'to były 2 jajka'));
        $this->assertStringContainsString("<opis>\nbez cukru\n</opis>", $text);
        $this->assertStringContainsString("<poprawka>\nto były 2 jajka\n</poprawka>", $text);
        $this->assertStringContainsString('Przeanalizuj posiłek ze zdjęcia.', $text);
        $this->assertStringContainsString('Przeanalizuj posiłek z opisu', MealPrompt::userText(new AnalysisRequest(description: 'zupa')));
    }

    // 2026-10-24, accuracy test (vault: Kalorie Accuracy Test): +13% kcal from a default 5 g of
    // oil, oversized bread/mozzarella portions and weighed grams from the description re-estimated.
    // Only guards the rules against being dropped; whether the model follows them is a manual retest.
    public function testSystemPromptCarriesAccuracyRules(): void
    {
        $this->assertStringNotContainsString('ukryty tłuszcz', MealPrompt::SYSTEM);
        $this->assertStringContainsString('Bez takich oznak nie doliczaj go', MealPrompt::SYSTEM);
        $this->assertStringContainsString('kromka chleba 30–35 g', MealPrompt::SYSTEM);
        $this->assertStringContainsString('kulka mozzarelli 125 g', MealPrompt::SYSTEM);
        $this->assertStringContainsString('jest faktem: przyjmij ją dokładnie', MealPrompt::SYSTEM);
        $this->assertStringContainsString('nie dodawaj do nich osobno oleju', MealPrompt::SYSTEM);
        $this->assertStringContainsString('Poprawka ma pierwszeństwo także przed gramaturą z opisu', MealPrompt::SYSTEM);
    }
}
