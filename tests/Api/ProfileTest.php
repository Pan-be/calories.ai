<?php

namespace App\Tests\Api;

final class ProfileTest extends ApiTestCase
{
    public function testProfileComputesGoals(): void
    {
        $token = $this->register();
        $body = $this->completeProfile($token);
        $user = $body['user'];
        $this->assertTrue($user['profile']['complete']);
        $this->assertSame(80.0, (float) $user['profile']['weight_kg']);
        // Mifflin-St Jeor: 10*80 + 6.25*180 - 5*age + 5; x1.55; minus 500 for "lose".
        $age = (new \DateTimeImmutable('1990-05-01'))->diff(new \DateTimeImmutable())->y;
        $bmr = 800 + 1125 - 5 * $age + 5;
        $this->assertSame((int) round($bmr), $body['computed']['bmr']);
        $this->assertSame((int) (round(($bmr * 1.55 - 500) / 10) * 10), $user['goals']['kcal']);
        $this->assertSame(160, $user['goals']['protein_g']); // 2.0 g/kg
        $this->assertFalse($user['goals']['manual']);
    }

    public function testRecompositionGoal(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        [$status, $body] = $this->api('PUT', '/api/me/profile', [
            'sex' => 'male', 'birth_date' => '1990-05-01', 'height_cm' => 180, 'weight_kg' => 80,
            'activity' => 'moderate', 'goal' => 'recomp', 'timezone' => 'Europe/Warsaw',
        ], $token);
        $this->assertSame(200, $status, json_encode($body));
        $age = (new \DateTimeImmutable('1990-05-01'))->diff(new \DateTimeImmutable())->y;
        $tdee = (800 + 1125 - 5 * $age + 5) * 1.55;
        // 10% under TDEE, at most 300 kcal; 2.2 g protein per kg.
        $kcal = (int) (round(($tdee - min(300, $tdee * 0.1)) / 10) * 10);
        $user = $body['user'];
        $this->assertSame('recomp', $user['profile']['goal']);
        $this->assertSame($kcal, $user['goals']['kcal']);
        $this->assertSame(176, $user['goals']['protein_g']);

        $b = $user['goal_breakdown'];
        $this->assertSame((int) round($tdee), $b['tdee']);
        $this->assertSame(1.55, $b['activity_factor']);
        $this->assertSame($kcal - (int) round($tdee), $b['adjust_kcal']);
        $this->assertSame(2.2, $b['protein_per_kg']);
        $this->assertSame($kcal, $b['kcal']);
    }

    public function testManualGoalsSurviveNewWeightUntilAuto(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        [$status, $body] = $this->api('PUT', '/api/me/goals', ['kcal' => 1900, 'protein_g' => 150, 'carbs_g' => 180, 'fat_g' => 60], $token);
        $this->assertSame(200, $status);
        $this->assertTrue($body['user']['goals']['manual']);

        [$status, $body] = $this->api('POST', '/api/weights', ['weight_kg' => 78.5], $token);
        $this->assertSame(201, $status);
        $this->assertSame(1900, $body['user']['goals']['kcal']);
        $this->assertSame(78.5, (float) $body['user']['profile']['weight_kg']);

        [$status, $body] = $this->api('PUT', '/api/me/goals', ['auto' => true], $token);
        $this->assertSame(200, $status);
        $this->assertFalse($body['user']['goals']['manual']);
        $this->assertNotSame(1900, $body['user']['goals']['kcal']);
    }

    public function testProfileValidation(): void
    {
        $token = $this->register();
        [$status, $body] = $this->api('PUT', '/api/me/profile', ['sex' => 'x'], $token);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('sex', $body['error']['message']);
        [$status] = $this->api('PUT', '/api/me/profile', [
            'sex' => 'female', 'birth_date' => '2030-01-01', 'height_cm' => 165, 'weight_kg' => 60, 'activity' => 'light', 'goal' => 'maintain',
        ], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('PUT', '/api/me/goals', ['auto' => true], $token);
        $this->assertSame(422, $status);
    }

    public function testWeightLog(): void
    {
        $token = $this->register();
        $this->api('POST', '/api/weights', ['weight_kg' => '81,2', 'day' => '2026-01-01'], $token);
        $this->api('POST', '/api/weights', ['weight_kg' => 80.4, 'day' => '2026-01-02'], $token);
        [$status] = $this->api('POST', '/api/weights', ['weight_kg' => 80.0, 'day' => '2026-01-02'], $token);
        $this->assertSame(201, $status);
        [$status] = $this->api('POST', '/api/weights', ['weight_kg' => 80.0, 'day' => '2999-01-01'], $token);
        $this->assertSame(422, $status);
        [, $body] = $this->api('GET', '/api/weights?days=3650', token: $token);
        $this->assertEquals([['day' => '2026-01-01', 'weight_kg' => 81.2], ['day' => '2026-01-02', 'weight_kg' => 80.0]], $body['entries']);
        [$status] = $this->api('DELETE', '/api/weights/2026-01-01', token: $token);
        $this->assertSame(200, $status);
        [$status] = $this->api('DELETE', '/api/weights/2026-01-01', token: $token);
        $this->assertSame(404, $status);
    }

    public function testPasswordChange(): void
    {
        $token = $this->register();
        [$status] = $this->api('PUT', '/api/me/password', ['current_password' => 'zle-haslo', 'new_password' => 'nowehaslo1'], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('PUT', '/api/me/password', ['current_password' => 'tajnehaslo1', 'new_password' => 'nowehaslo1'], $token);
        $this->assertSame(200, $status);
        [$status] = $this->api('POST', '/api/auth/login', ['email' => 'konrad@example.com', 'password' => 'nowehaslo1']);
        $this->assertSame(200, $status);
    }
}
