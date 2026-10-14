<?php

namespace App\Tests\Unit;

use App\Ai\MealSuggestions;
use App\Ai\SuggestionPrompt;
use App\Ai\SuggestionRequest;
use PHPUnit\Framework\TestCase;

final class SuggestionPromptTest extends TestCase
{
    public function testPromptCarriesWhatIsLeftHabitsAndTraining(): void
    {
        $r = new SuggestionRequest(
            goals: ['kcal' => 2400, 'protein_g' => 160, 'carbs_g' => 260, 'fat_g' => 70],
            eaten: ['kcal' => 1700, 'protein_g' => 80, 'carbs_g' => 195, 'fat_g' => 75],
            localTime: '19:40',
            mealsToday: ['Owsianka'],
            frequent: [['name' => 'Skyr z borówkami', 'times' => 6, 'kcal' => 250, 'protein_g' => 33, 'carbs_g' => 26, 'fat_g' => 0]],
            workout: 'siłownia: nogi (intensywność: mocny)',
            note: 'mam ryż',
        );
        $this->assertSame(['kcal' => 700.0, 'protein_g' => 80.0, 'carbs_g' => 65.0, 'fat_g' => 0.0], $r->remaining());
        $text = SuggestionPrompt::userText($r);
        $this->assertStringContainsString('Godzina: 19:40.', $text);
        $this->assertStringContainsString('Zostało: 700 kcal, białko 80 g, węglowodany 65 g, tłuszcz 0 g.', $text);
        $this->assertStringContainsString('- Skyr z borówkami (6×', $text);
        $this->assertStringContainsString("<trening>\nsiłownia: nogi", $text);
        $this->assertStringContainsString("<notatka>\nmam ryż\n</notatka>", $text);
    }

    public function testOutputIsCleanedAndCappedAtThree(): void
    {
        $item = ['name' => 'Twaróg', 'portion' => '200 g', 'grams' => 200, 'kcal' => 180, 'protein_g' => 36, 'carbs_g' => 7, 'fat_g' => -5];
        $out = MealSuggestions::fromOutput(['suggestions' => [
            ['name' => 'A', 'why' => 'x', 'items' => [$item, $item]],
            ['name' => 'Pusta', 'why' => 'x', 'items' => []],
            ['name' => 'B', 'items' => [$item]],
            ['name' => '', 'why' => 'x', 'items' => [$item]],
            ['name' => 'D', 'why' => 'x', 'items' => [$item]],
        ], 'notes' => 'ok'], 'sonnet');

        $this->assertSame(['A', 'B', 'Propozycja'], array_column($out->suggestions, 'name'));
        $this->assertEquals(['kcal' => 360, 'protein_g' => 72, 'carbs_g' => 14, 'fat_g' => 0], $out->suggestions[0]['totals']);
        $this->assertSame('', $out->suggestions[1]['why']);
    }
}
