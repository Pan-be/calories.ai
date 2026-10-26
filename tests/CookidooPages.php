<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The HTTP client in tests: Cookidoo recipe pages come from tests/fixtures/cookidoo
 * (made-up pages), r100003 redirects away, anything else is a 404. No request leaves the machine.
 */
final class CookidooPages
{
    /** @var list<string> */
    public static array $requested = [];

    /** @var list<array<string, mixed>> the options of each request */
    public static array $options = [];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        self::$requested[] = $url;
        self::$options[] = $options;
        $file = preg_match('~^https://cookidoo\.[a-z.]+/recipes/recipe/[a-z]{2}-[A-Z]{2}/(r\d+)$~', $url, $m) === 1
            ? __DIR__ . "/fixtures/cookidoo/{$m[1]}.html" : null;
        if (str_ends_with($url, '/r100003')) {
            return new MockResponse('', ['http_code' => 307, 'url' => $url, 'response_headers' => ['Location' => 'https://example.com/']]);
        }
        if ($file === null || !is_file($file)) {
            return new MockResponse('not found', ['http_code' => 404, 'url' => $url]);
        }
        return new MockResponse((string) file_get_contents($file), ['http_code' => 200, 'url' => $url]);
    }
}
