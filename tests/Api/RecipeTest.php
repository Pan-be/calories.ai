<?php

namespace App\Tests\Api;

/** Recipes from tests/fixtures/recipes.json (made up; the real file stays out of git). */
final class RecipeTest extends ApiTestCase
{
    public function testSearchIgnoresCaseAndDiacriticsAndRanksNamesFirst(): void
    {
        $token = $this->register();
        [$status, $body] = $this->api('GET', '/api/recipes?q=OWSIANKA%20jablkiem', token: $token);
        $this->assertSame(200, $status);
        $this->assertSame(['P01-D1-1'], array_column($body['recipes'], 'id'));
        $recipe = $body['recipes'][0];
        $this->assertSame('Owsianka z jabłkiem i cynamonem', $recipe['name']);
        $this->assertEquals(['kcal' => 400, 'protein_g' => 12, 'carbs_g' => 64, 'fat_g' => 10, 'grams' => 300],
            array_intersect_key($recipe, array_flip(['kcal', 'protein_g', 'carbs_g', 'fat_g', 'grams'])));
        $this->assertCount(3, $recipe['ingredients']);

        // "pomidor" is in the Greek salad's ingredients only; "ryż" is in a name.
        [, $body] = $this->api('GET', '/api/recipes?q=pomidor', token: $token);
        $this->assertSame(['P02-D3-4'], array_column($body['recipes'], 'id'));
        [, $body] = $this->api('GET', '/api/recipes?q=kurczak%20ryz', token: $token);
        $this->assertSame(['P01-D1-3'], array_column($body['recipes'], 'id'));
        [, $body] = $this->api('GET', '/api/recipes?q=pizza', token: $token);
        $this->assertSame([], $body['recipes']);
    }

    public function testMealFromRecipePortions(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        [$status, $body] = $this->api('POST', '/api/meals/recipe', token: $token,
            form: ['recipe_id' => 'P01-D1-1', 'portions' => '1,5', 'eaten_at' => '2026-10-26T08:00:00+01:00']);
        $this->assertSame(201, $status, json_encode($body));
        $meal = $body['meal'];
        $this->assertSame('recipe', $meal['source']);
        $this->assertSame('Owsianka z jabłkiem i cynamonem', $meal['name']);
        $this->assertSame('2026-10-26', $meal['day']);
        $this->assertNull($meal['photo_url']);
        $this->assertEquals([[
            'name' => 'Owsianka z jabłkiem i cynamonem', 'portion' => '1,5 porcji', 'grams' => 450,
            'kcal' => 600, 'protein_g' => 18, 'carbs_g' => 96, 'fat_g' => 15,
        ]], $meal['items']);
        $this->assertSame('Przepis (1,5 porcji): Płatki owsiane 90 g, Mleko 2% 300 g, Jabłko 60 g', $meal['description']);

        // No AI call is made, so the hourly analysis cap is not touched.
        [, $day] = $this->api('GET', '/api/days/2026-10-26', token: $token);
        $this->assertEquals(600, $day['totals']['kcal']);
    }

    public function testMealFromRecipeGramsAndDefaultPortion(): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/meals/recipe', token: $token, form: ['recipe_id' => 'P01-D1-3', 'grams' => '200']);
        $item = $body['meal']['items'][0];
        $this->assertSame('', $item['portion']);
        $this->assertEquals([200, 300, 22.5, 35, 7], [$item['grams'], $item['kcal'], $item['protein_g'], $item['carbs_g'], $item['fat_g']]);
        $this->assertStringStartsWith('Przepis (200 g): Pierś z kurczaka 75 g', $body['meal']['description']);

        [, $body] = $this->api('POST', '/api/meals/recipe', token: $token, form: ['recipe_id' => 'P01-D1-3']);
        $this->assertSame('1 porcja', $body['meal']['items'][0]['portion']);
        $this->assertEquals(600, $body['meal']['totals']['kcal']);
        [, $body] = $this->api('POST', '/api/meals/recipe', token: $token, form: ['recipe_id' => 'P01-D1-3', 'portions' => '2']);
        $this->assertSame('2 porcje', $body['meal']['items'][0]['portion']);
    }

    public function testMealFromRecipeWithPhoto(): void
    {
        $token = $this->register();
        [$status, $body] = $this->api('POST', '/api/meals/recipe', token: $token,
            files: ['photo' => $this->jpeg(800, 600)], form: ['recipe_id' => 'P02-D3-4']);
        $this->assertSame(201, $status, json_encode($body));
        $this->assertNotNull($body['meal']['photo_url']);
        $this->assertEquals(320, $body['meal']['totals']['kcal']);
    }

    public function testRejectsUnknownRecipeAndBadAmounts(): void
    {
        $token = $this->register();
        foreach ([
            ['recipe_id' => 'P99-D1-1'],
            ['portions' => '1'],
            ['recipe_id' => 'P01-D1-1', 'portions' => '0'],
            ['recipe_id' => 'P01-D1-1', 'portions' => '11'],
            ['recipe_id' => 'P01-D1-1', 'grams' => '0'],
        ] as $form) {
            [$status, $body] = $this->api('POST', '/api/meals/recipe', token: $token, form: $form);
            $this->assertSame(422, $status, json_encode($form));
            $this->assertNotEmpty($body['error']['message']);
        }
        [, $summary] = $this->api('GET', '/api/summary?days=1', token: $token);
        $this->assertSame(0, $summary['days'][0]['meals']);
    }

    public function testRecentRecipesWhenNothingIsTyped(): void
    {
        $token = $this->register();
        [, $body] = $this->api('GET', '/api/recipes', token: $token);
        $this->assertSame(['recipes' => [], 'recent' => true], $body);

        $this->api('POST', '/api/meals/recipe', token: $token, form: ['recipe_id' => 'P02-D3-4', 'eaten_at' => '2026-10-20T19:00:00+02:00']);
        $this->api('POST', '/api/meals/recipe', token: $token, form: ['recipe_id' => 'P01-D1-1', 'eaten_at' => '2026-10-25T08:00:00+02:00']);
        $this->api('POST', '/api/meals/recipe', token: $token, form: ['recipe_id' => 'P02-D3-4', 'eaten_at' => '2026-10-21T19:00:00+02:00']);
        // A hand-entered meal with a recipe's name is not a recipe meal.
        $this->api('POST', '/api/meals', ['name' => 'Kurczak z ryżem i brokułem', 'items' => [['name' => 'x', 'kcal' => 1]]], $token);

        [, $body] = $this->api('GET', '/api/recipes', token: $token);
        $this->assertSame(['P01-D1-1', 'P02-D3-4'], array_column($body['recipes'], 'id'));

        // Another user's recipe meals are not shown.
        $other = $this->register('ewelina@example.com');
        [, $body] = $this->api('GET', '/api/recipes', token: $other);
        $this->assertSame([], $body['recipes']);
    }

    public function testNeedsLogin(): void
    {
        [$status] = $this->api('GET', '/api/recipes?q=owsianka');
        $this->assertSame(401, $status);
        [$status] = $this->api('POST', '/api/meals/recipe', form: ['recipe_id' => 'P01-D1-1']);
        $this->assertSame(401, $status);
    }
}
