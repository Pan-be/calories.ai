<?php

namespace App\Tests\Api;

final class SuggestionTest extends ApiTestCase
{
    public function testSuggestionsAndPickingOne(): void
    {
        $token = $this->register();
        $profile = $this->completeProfile($token);
        $this->api('POST', '/api/meals/describe', ['text' => 'jajecznica i chleb'], $token);

        [$status, $body] = $this->api('POST', '/api/suggestions', ['note' => 'Coś ze skyrem'], $token);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertCount(3, $body['suggestions']);
        $first = $body['suggestions'][0];
        $this->assertSame('Coś ze skyrem', $first['name']);
        $this->assertEquals(['kcal' => 246, 'protein_g' => 34, 'carbs_g' => 26, 'fat_g' => 0], $first['totals']);
        $this->assertEquals($profile['user']['goals']['kcal'] - 300, $body['remaining']['kcal']);
        $this->assertSame('fake', $body['ai_model']);

        [$status, $meal] = $this->api('POST', '/api/meals', ['name' => $first['name'], 'items' => $first['items'], 'source' => 'suggestion'], $token);
        $this->assertSame(201, $status, json_encode($meal));
        $this->assertSame('suggestion', $meal['meal']['source']);
        $this->assertEquals(246, $meal['meal']['totals']['kcal']);

        [$status] = $this->api('POST', '/api/meals', ['name' => 'x', 'items' => $first['items'], 'source' => 'photo'], $token);
        $this->assertSame(422, $status); // only manual or suggestion by hand
    }

    public function testSuggestionsShareTheAiQuota(): void
    {
        $token = $this->register();
        $before = $_SERVER['KALORIE_ANALYSES_PER_HOUR'];
        $_SERVER['KALORIE_ANALYSES_PER_HOUR'] = $_ENV['KALORIE_ANALYSES_PER_HOUR'] = '2';
        try {
            static::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->api('POST', '/api/meals/describe', ['text' => 'jajecznica'], $token);
            [$status] = $this->api('POST', '/api/suggestions', [], $token);
            $this->assertSame(200, $status);
            [$status, $body] = $this->api('POST', '/api/suggestions', [], $token);
            $this->assertSame(429, $status);
            $this->assertSame('too_many_analyses', $body['error']['code']);
        } finally {
            $_SERVER['KALORIE_ANALYSES_PER_HOUR'] = $_ENV['KALORIE_ANALYSES_PER_HOUR'] = $before;
        }
    }

    public function testNeedsLogin(): void
    {
        [$status] = $this->api('POST', '/api/suggestions', []);
        $this->assertSame(401, $status);
    }
}
