<?php

namespace App\Tests\Api;

use App\Tests\ScaleFrames;

final class ScaleTest extends ApiTestCase
{
    public function testWeighingIsSavedAndBecomesTheDaysWeight(): void
    {
        $token = $this->register();
        $this->completeProfile($token);

        [$status, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);
        $this->assertSame(201, $status, json_encode($body));
        $m = $body['measurement'];
        $this->assertSame(76.8, $m['weight_kg']);
        $this->assertNull($m['impedances']);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
        $this->assertSame($today, $m['day']);
        $this->assertNotEmpty($m['measured_at']);
        // The response carries the user, so the app can show the goals recomputed from the new weight.
        $this->assertArrayHasKey('goals', $body['user']);

        [, $weights] = $this->api('GET', '/api/weights', token: $token);
        $this->assertSame([['day' => $today, 'weight_kg' => 76.8]], $weights['entries']);
    }

    public function testASecondWeighingTheSameDayKeepsBothAndTheLatestIsTheDaysWeight(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        $this->api('POST', '/api/scale/measurements', ['weight_kg' => 77.2], $token);
        $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);

        [$status, $body] = $this->api('GET', '/api/scale/measurements', token: $token);
        $this->assertSame(200, $status);
        $this->assertSame([77.2, 76.8], array_column($body['measurements'], 'weight_kg')); // oldest first
        [, $weights] = $this->api('GET', '/api/weights', token: $token);
        $this->assertSame([76.8], array_column($weights['entries'], 'weight_kg'));
    }

    public function testTheImpedanceFrameIsAttachedAndDecoded(): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);
        $id = $body['measurement']['id'];

        [$status, $body] = $this->api('PUT', "/api/scale/measurements/$id/frame", ['frame' => ScaleFrames::REAL], $token);
        $this->assertSame(200, $status, json_encode($body));
        // JSON has no float type, so 2871.0 comes back as 2871: compare values, not types.
        $this->assertEquals(ScaleFrames::REAL_IMPEDANCES, $body['measurement']['impedances']);

        // Sending it again (a retry after a lost response) is fine and changes nothing.
        [$status, $again] = $this->api('PUT', "/api/scale/measurements/$id/frame", ['frame' => strtoupper(ScaleFrames::REAL)], $token);
        $this->assertSame(200, $status);
        $this->assertSame($body['measurement'], $again['measurement']);

        [, $list] = $this->api('GET', '/api/scale/measurements', token: $token);
        $this->assertEquals(ScaleFrames::REAL_IMPEDANCES, $list['measurements'][0]['impedances']);
    }

    public function testFrameWithSpacesAsTheTestPageCopiesItIsAccepted(): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);
        $spaced = trim(chunk_split(ScaleFrames::REAL, 2, ' '));

        [$status, $body] = $this->api('PUT', "/api/scale/measurements/{$body['measurement']['id']}/frame", ['frame' => $spaced], $token);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertCount(22, $body['measurement']['impedances']);
    }

    /** @return iterable<string, array{string}> */
    public static function badFrames(): iterable
    {
        $real = ScaleFrames::REAL;
        yield 'not hex' => ['zz' . substr($real, 2)];
        yield 'odd length' => [substr($real, 0, -1)];
        yield 'empty' => [''];
        yield 'too short' => [substr($real, 0, 200)];
        yield 'wrong checksum' => [substr($real, 0, -2) . '95'];
        yield 'live weight frame' => ['33cc11000003800000000000020103009a'];
        yield 'wrong start' => [ScaleFrames::withChecksum('44' . substr($real, 2))];
        yield 'wrong frame type' => [ScaleFrames::withChecksum(substr_replace($real, '02', 8, 2))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFrames')]
    public function testABrokenFrameIsRefused(string $frame): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);
        $id = $body['measurement']['id'];

        [$status, $body] = $this->api('PUT', "/api/scale/measurements/$id/frame", ['frame' => $frame], $token);
        $this->assertSame(422, $status, json_encode($body));
        [, $list] = $this->api('GET', '/api/scale/measurements', token: $token);
        $this->assertNull($list['measurements'][0]['impedances']);
    }

    public function testAnotherValidFrameIsDecodedNotMatchedAgainstAFixture(): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);
        // First impedance 0x45337000 (2871.0) → 0x45338000 (2872.0), checksum adjusted.
        $frame = ScaleFrames::withChecksum(substr_replace(ScaleFrames::REAL, '45338000', 28 * 2, 8));

        [$status, $body] = $this->api('PUT', "/api/scale/measurements/{$body['measurement']['id']}/frame", ['frame' => $frame], $token);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertEquals(2872, $body['measurement']['impedances'][0]);
    }

    public function testAFrameFromAnotherWeighingIsRefused(): void
    {
        $token = $this->register();
        // The frame says 76.80 kg; this measurement is 78.55 kg.
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 78.55], $token);

        [$status, $body] = $this->api('PUT', "/api/scale/measurements/{$body['measurement']['id']}/frame", ['frame' => ScaleFrames::REAL], $token);
        $this->assertSame(422, $status);
        $this->assertSame('frame_mismatch', $body['error']['code']);
    }

    public function testWeightIsValidated(): void
    {
        $token = $this->register();
        foreach ([null, 0, -5, 'abc', 401] as $kg) {
            [$status] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => $kg], $token);
            $this->assertSame(422, $status, 'weight ' . var_export($kg, true));
        }
        [, $list] = $this->api('GET', '/api/scale/measurements', token: $token);
        $this->assertSame([], $list['measurements']);
    }

    public function testMeasurementsArePrivate(): void
    {
        $konrad = $this->register();
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $konrad);
        $id = $body['measurement']['id'];

        $other = $this->register('ewelina@example.com');
        [, $list] = $this->api('GET', '/api/scale/measurements', token: $other);
        $this->assertSame([], $list['measurements']);
        [$status] = $this->api('PUT', "/api/scale/measurements/$id/frame", ['frame' => ScaleFrames::REAL], $other);
        $this->assertSame(404, $status);
        [$status] = $this->api('DELETE', "/api/scale/measurements/$id", token: $other);
        $this->assertSame(404, $status);

        [$status] = $this->api('GET', '/api/scale/measurements');
        $this->assertSame(401, $status);
        [$status] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8]);
        $this->assertSame(401, $status);
    }

    public function testDeletingTheLatestWeighingGivesTheDayThePreviousOne(): void
    {
        // 2026-10-27: a wrong weighing (someone else on the scale) is removed and the day goes back to the right one.
        $token = $this->register();
        $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 61.3], $token);
        $id = $body['measurement']['id'];

        [$status, $body] = $this->api('DELETE', "/api/scale/measurements/$id", token: $token);
        $this->assertSame(200, $status);
        $this->assertSame(76.8, $body['user']['profile']['weight_kg']);
        [, $weights] = $this->api('GET', '/api/weights', token: $token);
        $this->assertSame([76.8], array_column($weights['entries'], 'weight_kg'));
        [, $list] = $this->api('GET', '/api/scale/measurements', token: $token);
        $this->assertSame([76.8], array_column($list['measurements'], 'weight_kg'));

        [$status] = $this->api('DELETE', "/api/scale/measurements/$id", token: $token);
        $this->assertSame(404, $status);
    }

    public function testDeletingAnEarlierWeighingKeepsTheDaysWeight(): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 77.2], $token);
        $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);

        $this->api('DELETE', "/api/scale/measurements/{$body['measurement']['id']}", token: $token);
        [, $weights] = $this->api('GET', '/api/weights', token: $token);
        $this->assertSame([76.8], array_column($weights['entries'], 'weight_kg'));
    }

    public function testDeletingTheOnlyWeighingOfTheDayRemovesTheDaysWeight(): void
    {
        $token = $this->register();
        $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
        $this->api('POST', '/api/weights', ['weight_kg' => 76.9, 'day' => $yesterday], $token);
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 61.3], $token);

        [, $body] = $this->api('DELETE', "/api/scale/measurements/{$body['measurement']['id']}", token: $token);
        $this->assertSame(76.9, $body['user']['profile']['weight_kg']); // the latest weight is yesterday's again
        [, $weights] = $this->api('GET', '/api/weights', token: $token);
        $this->assertSame([$yesterday], array_column($weights['entries'], 'day'));
    }

    public function testAWeightTypedByHandAfterTheWeighingIsKept(): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/scale/measurements', ['weight_kg' => 76.8], $token);
        $this->api('POST', '/api/weights', ['weight_kg' => 75.4], $token);

        $this->api('DELETE', "/api/scale/measurements/{$body['measurement']['id']}", token: $token);
        [, $weights] = $this->api('GET', '/api/weights', token: $token);
        $this->assertSame([75.4], array_column($weights['entries'], 'weight_kg'));
    }
}
