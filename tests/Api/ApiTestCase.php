<?php

namespace App\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        static::getContainer()->get('cache.rate_limiter')->clear();
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir') . '/var/test-photos');
    }

    /** @return array{0:int, 1:array} status and decoded JSON */
    protected function api(string $method, string $uri, ?array $body = null, ?string $token = null, array $files = [], array $form = []): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request($method, $uri, $form, $files, $server, $body === null ? null : json_encode($body));
        $response = $this->client->getResponse();
        $json = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($json) ? $json : []];
    }

    protected function register(string $email = 'konrad@example.com', string $password = 'tajnehaslo1'): string
    {
        [$status, $body] = $this->api('POST', '/api/auth/register', ['email' => $email, 'password' => $password]);
        $this->assertSame(201, $status, json_encode($body));
        return $body['token'];
    }

    protected function completeProfile(string $token): array
    {
        [$status, $body] = $this->api('PUT', '/api/me/profile', [
            'sex' => 'male', 'birth_date' => '1990-05-01', 'height_cm' => 180, 'weight_kg' => 80,
            'activity' => 'moderate', 'goal' => 'lose', 'timezone' => 'Europe/Warsaw',
        ], $token);
        $this->assertSame(200, $status, json_encode($body));
        return $body;
    }
}
