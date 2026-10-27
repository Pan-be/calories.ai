<?php

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Accounts beyond the first are made by the admin on the server, registration stays closed. */
final class CreateUserCommandTest extends ApiTestCase
{
    private function command(): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find('app:user:create'));
    }

    public function testCreatesAnAccountThatCanLogInWhileRegistrationStaysClosed(): void
    {
        $_SERVER['KALORIE_REGISTRATION'] = $_ENV['KALORIE_REGISTRATION'] = 'first';
        try {
            self::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->register(); // the first account, so registration is now closed

            $tester = $this->command();
            $this->assertSame(0, $tester->execute(['email' => ' Ewelina@Example.com ']));
            $this->assertSame(1, preg_match('/Hasło: (\S{16,})/', $tester->getDisplay(), $m), $tester->getDisplay());

            [$status, $body] = $this->api('POST', '/api/auth/login', ['email' => 'ewelina@example.com', 'password' => $m[1]]);
            $this->assertSame(200, $status, json_encode($body));
            $this->assertSame('ewelina@example.com', $body['user']['email']);
            $this->assertSame('Europe/Warsaw', $body['user']['timezone']);

            [$status] = $this->api('POST', '/api/auth/register', ['email' => 'obcy@example.com', 'password' => 'tajnehaslo1']);
            $this->assertSame(403, $status);
        } finally {
            $_SERVER['KALORIE_REGISTRATION'] = $_ENV['KALORIE_REGISTRATION'] = 'open';
        }
    }

    public function testEachAccountGetsADifferentPassword(): void
    {
        $a = $this->command();
        $a->execute(['email' => 'a@example.com']);
        $b = $this->command();
        $b->execute(['email' => 'b@example.com']);
        preg_match('/Hasło: (\S+)/', $a->getDisplay(), $pa);
        preg_match('/Hasło: (\S+)/', $b->getDisplay(), $pb);
        $this->assertNotSame($pa[1], $pb[1]);
    }

    public function testRefusesATakenOrInvalidEmail(): void
    {
        $this->register('konrad@example.com');

        $tester = $this->command();
        $this->assertNotSame(0, $tester->execute(['email' => 'KONRAD@example.com']));
        $this->assertStringContainsString('już istnieje', $tester->getDisplay());

        $tester = $this->command();
        $this->assertNotSame(0, $tester->execute(['email' => 'nie-email']));
        [$status] = $this->api('POST', '/api/auth/login', ['email' => 'nie-email', 'password' => 'x']);
        $this->assertSame(401, $status);
    }
}
