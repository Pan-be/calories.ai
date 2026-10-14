<?php

namespace App\Tests\Api;

final class WorkoutTest extends ApiTestCase
{
    public function testLogTrainingRestAndQuickPicks(): void
    {
        $token = $this->register();
        $this->completeProfile($token);

        [$status, $body] = $this->api('PUT', '/api/workouts/2026-10-10', ['description' => 'Siłownia: nogi, 60 min', 'intensity' => 'hard'], $token);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(['day' => '2026-10-10', 'rest' => false, 'description' => 'Siłownia: nogi, 60 min', 'intensity' => 'hard'], $body['workout']);

        // Saving the same day again replaces the entry; a rest day drops the description.
        [, $body] = $this->api('PUT', '/api/workouts/2026-10-10', ['rest' => true, 'description' => 'ignored'], $token);
        $this->assertSame(['day' => '2026-10-10', 'rest' => true, 'description' => null, 'intensity' => null], $body['workout']);

        $this->api('PUT', '/api/workouts/2026-10-11', ['description' => 'Bieganie 5 km'], $token);
        $this->api('PUT', '/api/workouts/2026-10-12', ['description' => 'siłownia: plecy'], $token);
        $this->api('PUT', '/api/workouts/2026-10-13', ['description' => 'Bieganie 5 km '], $token);

        [$status, $list] = $this->api('GET', '/api/workouts?days=3650', token: $token);
        $this->assertSame(200, $status);
        $this->assertCount(4, $list['entries']);
        $this->assertSame('2026-10-10', $list['entries'][0]['day']);
        $this->assertSame(['Bieganie 5 km', 'siłownia: plecy'], $list['frequent']);

        [, $day] = $this->api('GET', '/api/days/2026-10-11', token: $token);
        $this->assertSame('Bieganie 5 km', $day['workout']['description']);
        [, $day] = $this->api('GET', '/api/days/2026-10-09', token: $token);
        $this->assertNull($day['workout']);

        [$status] = $this->api('DELETE', '/api/workouts/2026-10-11', token: $token);
        $this->assertSame(200, $status);
        [$status] = $this->api('DELETE', '/api/workouts/2026-10-11', token: $token);
        $this->assertSame(404, $status);
    }

    public function testValidation(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        [$status] = $this->api('PUT', '/api/workouts/2026-10-10', ['intensity' => 'hard'], $token);
        $this->assertSame(422, $status); // a training needs a description
        [$status] = $this->api('PUT', '/api/workouts/2026-10-10', ['description' => 'x', 'intensity' => 'extreme'], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('PUT', '/api/workouts/2999-01-01', ['rest' => true], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('PUT', '/api/workouts/10-10-2026', ['rest' => true], $token);
        $this->assertSame(422, $status);
    }

    public function testEntriesArePerUser(): void
    {
        $token = $this->register();
        $this->api('PUT', '/api/workouts/2026-10-10', ['description' => 'Rower'], $token);
        static::getContainer()->get('cache.rate_limiter')->clear();
        [, $other] = $this->api('POST', '/api/auth/register', ['email' => 'inny@example.com', 'password' => 'tajnehaslo2']);
        [, $list] = $this->api('GET', '/api/workouts?days=3650', token: $other['token']);
        $this->assertSame([], $list['entries']);
        [$status] = $this->api('DELETE', '/api/workouts/2026-10-10', token: $other['token']);
        $this->assertSame(404, $status);
    }
}
