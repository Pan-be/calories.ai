<?php

namespace App\Tests\Api;

final class AuthTest extends ApiTestCase
{
    public function testRegisterLoginLogout(): void
    {
        $token = $this->register();
        [$status, $body] = $this->api('GET', '/api/me', token: $token);
        $this->assertSame(200, $status);
        $this->assertSame('konrad@example.com', $body['user']['email']);
        $this->assertFalse($body['user']['profile']['complete']);

        [$status, $body] = $this->api('POST', '/api/auth/login', ['email' => 'KONRAD@example.com', 'password' => 'tajnehaslo1']);
        $this->assertSame(200, $status);
        $second = $body['token'];
        $this->assertNotSame($token, $second);

        [$status] = $this->api('POST', '/api/auth/logout', [], $second);
        $this->assertSame(200, $status);
        [$status, $body] = $this->api('GET', '/api/me', token: $second);
        $this->assertSame(401, $status);
        $this->assertSame('unauthenticated', $body['error']['code']);
        // The first session is still valid.
        [$status] = $this->api('GET', '/api/me', token: $token);
        $this->assertSame(200, $status);
    }

    public function testBadInputAndDuplicates(): void
    {
        [$status, $body] = $this->api('POST', '/api/auth/register', ['email' => 'nie-mail', 'password' => 'tajnehaslo1']);
        $this->assertSame(422, $status);
        $this->assertSame('invalid', $body['error']['code']);
        [$status] = $this->api('POST', '/api/auth/register', ['email' => 'a@example.com', 'password' => 'krotkie']);
        $this->assertSame(422, $status);
        $this->register('a@example.com');
        [$status, $body] = $this->api('POST', '/api/auth/register', ['email' => 'a@example.com', 'password' => 'tajnehaslo1']);
        $this->assertSame(409, $status);
        [$status, $body] = $this->api('POST', '/api/auth/login', ['email' => 'a@example.com', 'password' => 'zlehaslo123']);
        $this->assertSame(401, $status);
        $this->assertSame('bad_credentials', $body['error']['code']);
    }

    public function testNoTokenAndBadToken(): void
    {
        [$status, $body] = $this->api('GET', '/api/me');
        $this->assertSame(401, $status);
        $this->assertSame('unauthenticated', $body['error']['code']);
        [$status] = $this->api('GET', '/api/me', token: 'zmyslony');
        $this->assertSame(401, $status);
        [$status, $body] = $this->api('GET', '/api/nie-ma-takiej', token: $this->register());
        $this->assertSame(404, $status);
        $this->assertSame('http_404', $body['error']['code']);
    }

    public function testLoginIsRateLimited(): void
    {
        $this->register('b@example.com');
        $this->client->disableReboot();
        $codes = [];
        for ($i = 0; $i < 11; $i++) {
            [$codes[]] = $this->api('POST', '/api/auth/login', ['email' => 'b@example.com', 'password' => 'zlehaslo123']);
        }
        $this->assertSame(401, $codes[9]);
        $this->assertSame(429, $codes[10]);
    }

    public function testFirstUserOnlyRegistration(): void
    {
        $_SERVER['KALORIE_REGISTRATION'] = $_ENV['KALORIE_REGISTRATION'] = 'first';
        try {
            self::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->register('first@example.com');
            [$status, $body] = $this->api('POST', '/api/auth/register', ['email' => 'second@example.com', 'password' => 'tajnehaslo1']);
            $this->assertSame(403, $status);
            $this->assertSame('registration_closed', $body['error']['code']);
        } finally {
            $_SERVER['KALORIE_REGISTRATION'] = $_ENV['KALORIE_REGISTRATION'] = 'open';
        }
    }
}
