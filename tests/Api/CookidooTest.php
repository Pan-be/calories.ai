<?php

namespace App\Tests\Api;

use App\Recipe\CookidooImporter;
use App\Tests\CookidooPages;

/** Cookidoo pages come from tests/fixtures/cookidoo (made up) via App\Tests\CookidooPages. */
final class CookidooTest extends ApiTestCase
{
    private const SHARED = 'Sprawdź ten pyszny przepis znaleziony na Cookidoo®: Zupa testowa https://cookidoo.pl/recipes/recipe/pl-PL/r100001';

    protected function setUp(): void
    {
        parent::setUp();
        CookidooPages::$requested = [];
    }

    public function testImportFromSharedTextReadsThePageOnce(): void
    {
        $token = $this->register();
        [$status, $body] = $this->api('POST', '/api/recipes/cookidoo', ['link' => self::SHARED], $token);
        $this->assertSame(200, $status, json_encode($body));
        $recipe = $body['recipe'];
        $this->assertSame('cookidoo-r100001', $recipe['id']);
        $this->assertSame('cookidoo', $recipe['source']);
        $this->assertSame('Zupa testowa z dyni & imbirem', $recipe['name']);
        $this->assertEquals([200, 4, 20.5, 10], [$recipe['kcal'], $recipe['protein_g'], $recipe['carbs_g'], $recipe['fat_g']]);
        // Per portion of 4: grams as given, a range's middle, 5 g a clove or teaspoon, 0.5 g a pinch, litres; a tin unknown.
        $this->assertEquals([
            ['name' => 'cebuli', 'amount' => '', 'grams' => 25],
            ['name' => 'imbiru, świeżego', 'amount' => '', 'grams' => 3.8],
            ['name' => 'czosnku', 'amount' => '2 ząbki', 'grams' => 2.5],
            ['name' => 'dyni', 'amount' => '', 'grams' => 150],
            ['name' => 'bulionu', 'amount' => '', 'grams' => 100],
            ['name' => 'soli', 'amount' => '1 łyżeczka', 'grams' => 1.3],
            ['name' => 'pieprzu', 'amount' => '1 szczypta', 'grams' => 0.1],
            ['name' => 'ciecierzycy', 'amount' => '1 puszka', 'grams' => 0],
        ], $recipe['ingredients']);
        $this->assertEquals(282.7, $recipe['grams']);

        // Again (as a bare link on another Cookidoo domain): from the database, no request.
        [$status, $body] = $this->api('POST', '/api/recipes/cookidoo', ['link' => 'https://cookidoo.de/recipes/recipe/pl-PL/r100001'], $token);
        $this->assertSame(200, $status);
        $this->assertSame('cookidoo-r100001', $body['recipe']['id']);
        $this->assertSame(['https://cookidoo.pl/recipes/recipe/pl-PL/r100001'], CookidooPages::$requested);

        // Now it's found by search like a Respo recipe.
        [, $body] = $this->api('GET', '/api/recipes?q=zupa%20dyni', token: $token);
        $this->assertSame(['cookidoo-r100001'], array_column($body['recipes'], 'id'));
    }

    public function testMealFromCookidooRecipeAndRecent(): void
    {
        $token = $this->register();
        $this->api('POST', '/api/recipes/cookidoo', ['link' => self::SHARED], $token);
        [$status, $body] = $this->api('POST', '/api/meals/recipe', token: $token, form: ['recipe_id' => 'cookidoo-r100001', 'portions' => '1.5']);
        $this->assertSame(201, $status, json_encode($body));
        $this->assertSame('cookidoo-r100001', $body['meal']['recipe_id']);
        $this->assertEquals(300, $body['meal']['totals']['kcal']);
        $this->assertSame('Przepis (1,5 porcji): cebuli 38 g, imbiru, świeżego 5,7 g, czosnku 3,8 g, dyni 225 g, bulionu 150 g, soli 2 g, pieprzu 0,2 g, 1 puszka ciecierzycy',
            $body['meal']['description']);

        [, $body] = $this->api('GET', '/api/recipes', token: $token);
        $this->assertSame(['cookidoo-r100001'], array_column($body['recipes'], 'id'));
    }

    public function testRejectsOtherLinksMissingPagesAndPagesWithoutValues(): void
    {
        $token = $this->register();
        foreach ([
            'zupa dyniowa' => 'To nie jest link',
            'https://example.com/recipes/recipe/pl-PL/r100001' => 'To nie jest link',
            'https://cookidoo.evil.com/recipes/recipe/pl-PL/r100001' => 'To nie jest link',
            'https://cookidoo.pl/recipes/recipe/pl-PL/r999999' => 'Nie ma takiego przepisu',
            'https://cookidoo.pl/recipes/recipe/pl-PL/r100002' => 'nie ma wartości odżywczych',
            'https://cookidoo.pl/recipes/recipe/pl-PL/r100003' => 'Nie udało się pobrać', // a redirect is not followed
        ] as $link => $message) {
            [$status, $body] = $this->api('POST', '/api/recipes/cookidoo', ['link' => $link], $token);
            $this->assertSame(422, $status, $link);
            $this->assertStringContainsString($message, $body['error']['message'], $link);
        }
        $this->assertSame([
            'https://cookidoo.pl/recipes/recipe/pl-PL/r999999',
            'https://cookidoo.pl/recipes/recipe/pl-PL/r100002',
            'https://cookidoo.pl/recipes/recipe/pl-PL/r100003',
        ], CookidooPages::$requested);
        [, $body] = $this->api('GET', '/api/recipes?q=przepis', token: $token);
        $this->assertSame([], $body['recipes']);
    }

    public function testNeedsLogin(): void
    {
        [$status] = $this->api('POST', '/api/recipes/cookidoo', ['link' => self::SHARED]);
        $this->assertSame(401, $status);
        $this->assertSame([], CookidooPages::$requested);
    }

    public function testYieldGivenAsAList(): void
    {
        // schema.org allows recipeYield as a list, e.g. ["4", "4 porcje"].
        $html = '<script type="application/ld+json">{"@type":"Recipe","name":"Test","recipeYield":["4","4 porcje"],'
            . '"nutrition":{"calories":"100 kcal"},"recipeIngredient":["400 g dyni"]}</script>';
        $recipe = CookidooImporter::parse($html, 'cookidoo-r1', 'u')->toArray();
        $this->assertEquals(100, $recipe['grams']);
    }
}
