<?php

namespace App\Tests\Api;

final class WorkoutTest extends ApiTestCase
{
    public function testSeveralTrainingsADay(): void
    {
        $token = $this->register();
        $this->completeProfile($token);

        [$status, $body] = $this->api('POST', '/api/workouts/2026-10-10', ['description' => 'Siłownia: nogi, 60 min', 'intensity' => 'hard', 'part_of_day' => 'evening'], $token);
        $this->assertSame(201, $status, json_encode($body));
        $gym = $body['workout'];
        $this->assertSame(['day' => '2026-10-10', 'rest' => false, 'description' => 'Siłownia: nogi, 60 min', 'intensity' => 'hard', 'part_of_day' => 'evening'], array_diff_key($gym, ['id' => 0]));
        [, $body] = $this->api('POST', '/api/workouts/2026-10-10', ['description' => 'Joga', 'part_of_day' => 'morning'], $token);
        $yoga = $body['workout'];

        // The day lists morning before evening, whatever the order they were added in.
        [, $day] = $this->api('GET', '/api/days/2026-10-10', token: $token);
        $this->assertSame(['Joga', 'Siłownia: nogi, 60 min'], array_column($day['workouts'], 'description'));

        [$status, $body] = $this->api('PATCH', '/api/workouts/' . $gym['id'], ['description' => 'Siłownia: plecy', 'intensity' => 'moderate'], $token);
        $this->assertSame(200, $status);
        $this->assertSame('Siłownia: plecy', $body['workout']['description']);
        $this->assertNull($body['workout']['part_of_day']); // PATCH replaces all three fields

        [$status] = $this->api('DELETE', '/api/workouts/' . $yoga['id'], token: $token);
        $this->assertSame(200, $status);
        [$status] = $this->api('DELETE', '/api/workouts/' . $yoga['id'], token: $token);
        $this->assertSame(404, $status);
        [, $day] = $this->api('GET', '/api/days/2026-10-10', token: $token);
        $this->assertCount(1, $day['workouts']);
        [, $day] = $this->api('GET', '/api/days/2026-10-09', token: $token);
        $this->assertSame([], $day['workouts']);
    }

    public function testRestDay(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        [$status, $body] = $this->api('POST', '/api/workouts/2026-10-10', ['rest' => true, 'description' => 'ignored'], $token);
        $this->assertSame(201, $status);
        $this->assertTrue($body['workout']['rest']);
        $this->assertNull($body['workout']['description']);
        $rest = $body['workout'];

        // Saving rest again keeps the one entry; editing it is refused.
        [$status, $body] = $this->api('POST', '/api/workouts/2026-10-10', ['rest' => true], $token);
        $this->assertSame(200, $status);
        $this->assertSame($rest['id'], $body['workout']['id']);
        [$status] = $this->api('PATCH', '/api/workouts/' . $rest['id'], ['description' => 'x'], $token);
        $this->assertSame(422, $status);

        // A training replaces the rest day; rest is then refused while the training is there.
        $this->api('POST', '/api/workouts/2026-10-10', ['description' => 'Spacer'], $token);
        [, $day] = $this->api('GET', '/api/days/2026-10-10', token: $token);
        $this->assertSame([false], array_column($day['workouts'], 'rest'));
        [$status] = $this->api('POST', '/api/workouts/2026-10-10', ['rest' => true], $token);
        $this->assertSame(422, $status);
    }

    public function testQuickPicksAndOldReplaceDay(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        $this->api('POST', '/api/workouts/2026-10-11', ['description' => 'Bieganie 5 km'], $token);
        $this->api('POST', '/api/workouts/2026-10-12', ['description' => 'siłownia: plecy'], $token);
        $this->api('POST', '/api/workouts/2026-10-13', ['description' => 'Bieganie 5 km '], $token);
        $this->api('POST', '/api/workouts/2026-10-13', ['description' => 'Joga'], $token);

        [$status, $list] = $this->api('GET', '/api/workouts?days=3650', token: $token);
        $this->assertSame(200, $status);
        $this->assertCount(4, $list['entries']);
        $this->assertSame('2026-10-11', $list['entries'][0]['day']);
        $this->assertSame(['Bieganie 5 km', 'Joga', 'siłownia: plecy'], $list['frequent']);

        // PUT (the app before several-per-day) replaces everything on that day with one entry.
        [$status, $body] = $this->api('PUT', '/api/workouts/2026-10-13', ['rest' => true], $token);
        $this->assertSame(200, $status);
        [, $day] = $this->api('GET', '/api/days/2026-10-13', token: $token);
        $this->assertSame([$body['workout']['id']], array_column($day['workouts'], 'id'));
    }

    public function testValidation(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        [$status] = $this->api('POST', '/api/workouts/2026-10-10', ['intensity' => 'hard'], $token);
        $this->assertSame(422, $status); // a training needs a description
        [$status] = $this->api('POST', '/api/workouts/2026-10-10', ['description' => 'x', 'intensity' => 'extreme'], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('POST', '/api/workouts/2026-10-10', ['description' => 'x', 'part_of_day' => 'night'], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('POST', '/api/workouts/2999-01-01', ['rest' => true], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('POST', '/api/workouts/2026-13-45', ['rest' => true], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('POST', '/api/workouts/10-10-2026', ['rest' => true], $token);
        $this->assertSame(404, $status);
    }

    public function testEntriesArePerUser(): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/workouts/2026-10-10', ['description' => 'Rower'], $token);
        $id = $body['workout']['id'];
        static::getContainer()->get('cache.rate_limiter')->clear();
        [, $other] = $this->api('POST', '/api/auth/register', ['email' => 'inny@example.com', 'password' => 'tajnehaslo2']);
        [, $list] = $this->api('GET', '/api/workouts?days=3650', token: $other['token']);
        $this->assertSame([], $list['entries']);
        [$status] = $this->api('DELETE', "/api/workouts/$id", token: $other['token']);
        $this->assertSame(404, $status);
        [$status] = $this->api('PATCH', "/api/workouts/$id", ['description' => 'x'], $other['token']);
        $this->assertSame(404, $status);
    }
}
