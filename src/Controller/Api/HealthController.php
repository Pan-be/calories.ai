<?php

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    /** For monitoring (Uptime Kuma): the database answers, and is the AI bridge up? */
    #[Route('/api/health', methods: ['GET'])]
    public function __invoke(
        Connection $db,
        #[Autowire(env: 'KALORIE_ANALYZER')] string $analyzer,
        #[Autowire(env: 'KALORIE_BRIDGE_SOCKET')] string $socket,
    ): JsonResponse {
        $dbOk = (int) $db->fetchOne('SELECT 1') === 1;
        $bridge = null;
        if ($analyzer === 'claude') {
            $ch = curl_init('http://bridge/v1/health');
            curl_setopt_array($ch, [CURLOPT_UNIX_SOCKET_PATH => $socket, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
            $bridge = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        }
        $ok = $dbOk && $bridge !== false;

        return $this->json(['ok' => $ok, 'database' => $dbOk, 'analyzer' => $analyzer, 'bridge' => $bridge], $ok ? 200 : 503);
    }
}
