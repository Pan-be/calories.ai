<?php

namespace App\Tests\Api;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MealTest extends ApiTestCase
{
    public function testMealFromDescriptionAndDayTotals(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        [$status, $body] = $this->api('POST', '/api/meals/describe', ['text' => 'jajecznica z dwóch jaj i kromka chleba', 'eaten_at' => '2026-10-13T08:30:00+02:00'], $token);
        $this->assertSame(201, $status, json_encode($body));
        $meal = $body['meal'];
        $this->assertSame('text', $meal['source']);
        $this->assertSame('2026-10-13', $meal['day']);
        $this->assertSame('2026-10-13T08:30:00+02:00', $meal['eaten_at']);
        $this->assertCount(2, $meal['items']);
        $this->assertEquals(['kcal' => 300, 'protein_g' => 17, 'carbs_g' => 20, 'fat_g' => 16], $meal['totals']);
        $this->assertSame(7, $meal['health_score']);

        [$status, $day] = $this->api('GET', '/api/days/2026-10-13', token: $token);
        $this->assertSame(200, $status);
        $this->assertCount(1, $day['meals']);
        $this->assertEquals(300, $day['totals']['kcal']);
        $this->assertEquals($day['goals']['kcal'] - 300, $day['remaining']['kcal']);

        [, $other] = $this->api('GET', '/api/days/2026-10-12', token: $token);
        $this->assertSame([], $other['meals']);
        [$status] = $this->api('GET', '/api/days/13-10-2026', token: $token);
        $this->assertSame(422, $status);
    }

    public function testLocalDayFollowsUserTimezone(): void
    {
        $token = $this->register();
        $this->completeProfile($token); // Europe/Warsaw
        // 23:30 UTC on the 12th is 01:30 on the 13th in Warsaw.
        [, $body] = $this->api('POST', '/api/meals', ['name' => 'Nocna przekąska', 'eaten_at' => '2026-10-12T23:30:00Z', 'items' => [['name' => 'Jabłko', 'kcal' => 80]]], $token);
        $this->assertSame('2026-10-13', $body['meal']['day']);
    }

    public function testMovingAMealToAnotherDay(): void
    {
        $token = $this->register();
        $this->completeProfile($token);
        // Logged on the wrong day (the one open in the app), then moved by editing the date only.
        [, $body] = $this->api('POST', '/api/meals', ['name' => 'Czekolada', 'eaten_at' => '2026-10-14T19:45:00+02:00', 'items' => [['name' => 'Czekolada', 'kcal' => 200]]], $token);
        $id = $body['meal']['id'];
        [$status, $body] = $this->api('PATCH', "/api/meals/$id", ['eaten_at' => '2026-10-16T19:45:00+02:00'], $token);
        $this->assertSame(200, $status);
        $this->assertSame('2026-10-16', $body['meal']['day']);
        $this->assertEquals(200, $body['meal']['totals']['kcal']);

        [, $old] = $this->api('GET', '/api/days/2026-10-14', token: $token);
        $this->assertSame([], $old['meals']);
        [, $new] = $this->api('GET', '/api/days/2026-10-16', token: $token);
        $this->assertSame([$id], array_column($new['meals'], 'id'));

        // Editing the items keeps the date.
        $this->api('PATCH', "/api/meals/$id", ['items' => [['name' => 'Czekolada', 'grams' => 50, 'kcal' => 270]]], $token);
        [, $body] = $this->api('GET', "/api/meals/$id", token: $token);
        $this->assertSame('2026-10-16T19:45:00+02:00', $body['meal']['eaten_at']);
    }

    public function testMealFromPhoto(): void
    {
        $token = $this->register();
        [$status, $body] = $this->api('POST', '/api/meals/photo', token: $token, files: ['photo' => $this->jpeg(3000, 2000)], form: ['note' => 'bez masła']);
        $this->assertSame(201, $status, json_encode($body));
        $meal = $body['meal'];
        $this->assertSame('photo', $meal['source']);
        $this->assertSame('bez masła', $meal['description']);
        $this->assertNotNull($meal['photo_url']);

        $this->client->request('GET', $meal['photo_url'], server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $response = $this->client->getInternalResponse();
        $this->assertSame(200, $response->getStatusCode());
        $size = getimagesizefromstring($response->getContent());
        $this->assertSame([1600, 1067], [$size[0], $size[1]]); // longest side capped at 1600
        $this->client->request('GET', $meal['thumb_url'], server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertSame(400, getimagesizefromstring($this->client->getInternalResponse()->getContent())[0]);

        // Someone else can't see it.
        $other = $this->register('obcy@example.com');
        $this->client->request('GET', $meal['photo_url'], server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $other]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        [$status] = $this->api('GET', '/api/meals/' . $meal['id'], token: $other);
        $this->assertSame(404, $status);
        [$status] = $this->api('DELETE', '/api/meals/' . $meal['id'], token: $other);
        $this->assertSame(404, $status);
    }

    public function testRejectsNonImagesAndHeic(): void
    {
        $token = $this->register();
        $txt = tempnam(sys_get_temp_dir(), 'kal');
        file_put_contents($txt, 'to nie jest zdjęcie');
        [$status, $body] = $this->api('POST', '/api/meals/photo', token: $token, files: ['photo' => new UploadedFile($txt, 'a.jpg', null, null, true)]);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('JPEG', $body['error']['message']);

        $heic = tempnam(sys_get_temp_dir(), 'kal');
        file_put_contents($heic, "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 100));
        [$status, $body] = $this->api('POST', '/api/meals/photo', token: $token, files: ['photo' => new UploadedFile($heic, 'a.heic', null, null, true)]);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('HEIC', $body['error']['message']);

        [$status] = $this->api('POST', '/api/meals/photo', token: $token);
        $this->assertSame(422, $status);
    }

    public function testNotFood(): void
    {
        $token = $this->register();
        [$status, $body] = $this->api('POST', '/api/meals/describe', ['text' => 'kamień z ogródka'], $token);
        $this->assertSame(422, $status);
        $this->assertSame('not_food', $body['error']['code']);
        [, $day] = $this->api('GET', '/api/summary?days=1', token: $token);
        $this->assertSame(0, $day['days'][0]['meals']);
    }

    public function testEditFixCopyDelete(): void
    {
        $token = $this->register();
        [, $body] = $this->api('POST', '/api/meals/photo', token: $token, files: ['photo' => $this->jpeg(800, 600)]);
        $id = $body['meal']['id'];

        [$status, $body] = $this->api('PATCH', "/api/meals/$id", ['name' => 'Moje śniadanie', 'items' => [
            ['name' => 'Jajka', 'portion' => '3 szt.', 'grams' => 150, 'kcal' => 210, 'protein_g' => 18, 'carbs_g' => 1, 'fat_g' => 15],
        ]], $token);
        $this->assertSame(200, $status);
        $this->assertSame('Moje śniadanie', $body['meal']['name']);
        $this->assertEquals(210, $body['meal']['totals']['kcal']);

        [$status] = $this->api('PATCH', "/api/meals/$id", ['items' => []], $token);
        $this->assertSame(422, $status);
        [$status] = $this->api('PATCH', "/api/meals/$id", ['items' => [['name' => 'X', 'kcal' => -5]]], $token);
        $this->assertSame(422, $status);

        // The fake analyzer adds butter to the previous items on a correction.
        [$status, $body] = $this->api('POST', "/api/meals/$id/fix", ['text' => 'było jeszcze masło'], $token);
        $this->assertSame(200, $status);
        $this->assertSame(['Jajka', 'Masło'], array_column($body['meal']['items'], 'name'));
        $this->assertEquals(247, $body['meal']['totals']['kcal']);

        [$status, $copy] = $this->api('POST', "/api/meals/$id/copy", ['eaten_at' => '2026-10-14T12:00:00+02:00'], $token);
        $this->assertSame(201, $status);
        $this->assertSame('2026-10-14', $copy['meal']['day']);
        $this->assertNotNull($copy['meal']['photo_url']);
        $this->assertEquals(247, $copy['meal']['totals']['kcal']);

        // The photo stays while the copy uses it, and goes with the last meal.
        $photoDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/test-photos';
        $this->assertCount(2, glob("$photoDir/*/*.jpg"));
        $this->api('DELETE', "/api/meals/$id", token: $token);
        $this->assertCount(2, glob("$photoDir/*/*.jpg"));
        [$status] = $this->api('DELETE', '/api/meals/' . $copy['meal']['id'], token: $token);
        $this->assertSame(200, $status);
        $this->assertCount(0, glob("$photoDir/*/*.jpg"));
        [$status] = $this->api('GET', "/api/meals/$id", token: $token);
        $this->assertSame(404, $status);
    }

    public function testSummary(): void
    {
        $token = $this->register();
        $this->api('POST', '/api/meals', ['name' => 'A', 'eaten_at' => '2026-10-10T12:00:00+02:00', 'items' => [['name' => 'a', 'kcal' => 500, 'protein_g' => 20]]], $token);
        $this->api('POST', '/api/meals', ['name' => 'B', 'eaten_at' => '2026-10-10T18:00:00+02:00', 'items' => [['name' => 'b', 'kcal' => 250], ['name' => 'c', 'kcal' => 50]]], $token);
        $this->api('POST', '/api/meals', ['name' => 'C', 'eaten_at' => '2026-10-12T18:00:00+02:00', 'items' => [['name' => 'd', 'kcal' => 100]]], $token);
        [$status, $body] = $this->api('GET', '/api/summary?days=3&end=2026-10-12', token: $token);
        $this->assertSame(200, $status);
        $this->assertSame(['2026-10-10', '2026-10-11', '2026-10-12'], array_column($body['days'], 'day'));
        $this->assertEquals([800, 0, 100], array_column($body['days'], 'kcal'));
        $this->assertSame([2, 0, 1], array_column($body['days'], 'meals'));
        $this->assertEquals(20, $body['days'][0]['protein_g']);
    }

    public function testHealth(): void
    {
        [$status, $body] = $this->api('GET', '/api/health');
        $this->assertSame(200, $status);
        $this->assertTrue($body['database']);
        $this->assertSame('fake', $body['analyzer']);
    }

    private function jpeg(int $w, int $h): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
        $path = tempnam(sys_get_temp_dir(), 'kal');
        imagejpeg($img, $path, 80);
        return new UploadedFile($path, 'meal.jpg', 'image/jpeg', null, true);
    }
}
