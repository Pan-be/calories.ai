<?php

namespace App\Tests\Api;

use App\Tests\ScaleFrames;

/**
 * Body composition from the scale's impedance frame (2026-11-08): whole body only, computed on
 * read from the stored frame and the profile, calibrated so a man's fat % lands near the scale's
 * own display. Frames without a real impedance measurement give no result and say why.
 */
final class BodyCompositionTest extends ApiTestCase
{
    /** Konrad's profile: the REAL frame is his 76.80 kg weighing of 2026-10-27, display 14.6 % fat, 62.7 % water. */
    private function konrad(?string $sex = 'male', ?float $height = 179): string
    {
        $token = $this->register();
        [$status, $body] = $this->api('PUT', '/api/me/profile', [
            'sex' => $sex, 'birth_date' => '1990-05-01', 'height_cm' => $height, 'weight_kg' => 76.8,
            'activity' => 'moderate', 'goal' => 'maintain', 'timezone' => 'Europe/Warsaw',
        ], $token);
        $this->assertSame(200, $status, json_encode($body));

        return $token;
    }

    /** @return array<string, mixed> the measurement after its frame is attached */
    private function weigh(string $token, float $kg, string $frame): array
    {
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => $kg], $token);
        [$status, $body] = $this->api('PUT', "/api/scale/measurements/{$body['measurement']['id']}/frame", ['frame' => $frame], $token);
        $this->assertSame(200, $status, json_encode($body));

        return $body['measurement'];
    }

    public function testAMansFatPercentLandsNearTheScaleDisplay(): void
    {
        $m = $this->weigh($this->konrad(), 76.8, ScaleFrames::REAL);

        $this->assertSame('ok', $m['body_status']);
        $body = $m['body'];
        $this->assertEqualsWithDelta(14.6, $body['fat_pct'], 1.0); // the display said 14.6
        $this->assertEqualsWithDelta(62.7, $body['water_pct'], 1.0); // the display said 62.7
        // The parts add up to the weight.
        $this->assertEqualsWithDelta(76.8, $body['fat_kg'] + $body['lean_kg'], 0.05);
        $this->assertEqualsWithDelta($body['fat_pct'] / 100 * 76.8, $body['fat_kg'], 0.05);
    }

    public function testTheListCarriesBodyCompositionToo(): void
    {
        $token = $this->konrad();
        $m = $this->weigh($token, 76.8, ScaleFrames::REAL);

        [, $list] = $this->api('GET', '/api/scale/measurements', token: $token);
        $this->assertSame($m['body'], $list['measurements'][0]['body']);
        $this->assertSame('ok', $list['measurements'][0]['body_status']);
    }

    public function testItIsComputedFromTheCurrentProfileSoHistoryFollowsAChange(): void
    {
        $token = $this->konrad(height: 179);
        $first = $this->weigh($token, 76.8, ScaleFrames::REAL);
        $this->assertSame('ok', $first['body_status']);
        $before = $first['body']['fat_pct'];
        [$status] = $this->api('PUT', '/api/me/profile', [
            'sex' => 'male', 'birth_date' => '1990-05-01', 'height_cm' => 195, 'weight_kg' => 76.8,
            'activity' => 'moderate', 'goal' => 'maintain', 'timezone' => 'Europe/Warsaw',
        ], $token);
        $this->assertSame(200, $status);

        [, $list] = $this->api('GET', '/api/scale/measurements', token: $token);
        // Taller at the same impedance and weight means more lean mass, so less fat.
        $this->assertSame('ok', $list['measurements'][0]['body_status']);
        $this->assertLessThan($before - 2, $list['measurements'][0]['body']['fat_pct']);
    }

    public function testAllZeroImpedancesAreSavedButGiveNoResult(): void
    {
        $m = $this->weigh($this->konrad(), 76.8, ScaleFrames::ZEROS);

        $this->assertSame('no_impedance', $m['body_status']);
        $this->assertNull($m['body']);
        $this->assertCount(22, $m['impedances']); // the raw frame is kept
    }

    public function testAPartialMeasurementWithJunkValuesGivesNoResult(): void
    {
        $m = $this->weigh($this->konrad(), 76.4, ScaleFrames::BROKEN);

        $this->assertSame('no_impedance', $m['body_status']);
        $this->assertNull($m['body']);
    }

    public function testAWeighingWithoutAFrameSaysSo(): void
    {
        $token = $this->konrad();
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);

        $this->assertSame('no_frame', $body['measurement']['body_status']);
        $this->assertNull($body['measurement']['body']);
    }

    public function testAWomanGetsNoResultUntilTheFormulaIsCalibratedForWomen(): void
    {
        $m = $this->weigh($this->konrad(sex: 'female', height: 165), 76.8, ScaleFrames::REAL);

        $this->assertSame('not_calibrated', $m['body_status']);
        $this->assertNull($m['body']);
    }

    public function testWithoutAProfileThereIsNoResult(): void
    {
        $token = $this->register(); // onboarding not done: no sex, height or birth date
        $m = $this->weigh($token, 76.8, ScaleFrames::REAL);

        $this->assertSame('no_profile', $m['body_status']);
        $this->assertNull($m['body']);
    }
}
