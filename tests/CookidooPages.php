<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The HTTP client in tests: Cookidoo recipe pages come from tests/fixtures/cookidoo
 * (made-up pages), anything else is a 404. No request leaves the machine.
 */
final class CookidooPages
{
    /** @var list<string> */
    public static array $requested = [];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        self::$requested[] = $url;
        $file = preg_match('~^https://cookidoo\.[a-z.]+/recipes/recipe/[a-z]{2}-[A-Z]{2}/(r\d+)$~', $url, $m) === 1
            ? __DIR__ . "/fixtures/cookidoo/{$m[1]}.html" : null;
        if ($file === null || !is_file($file)) {
            return new MockResponse('not found', ['http_code' => 404, 'url' => $url]);
        }
        return new MockResponse((string) file_get_contents($file), ['http_code' => 200, 'url' => $url]);
    }
}
