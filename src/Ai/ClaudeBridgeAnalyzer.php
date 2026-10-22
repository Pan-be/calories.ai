<?php

namespace App\Ai;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Claude on Konrad's subscription, through the host bridge (bridge/claude_bridge.py)
 * listening on a Unix socket mounted into the container.
 */
final class ClaudeBridgeAnalyzer implements MealAnalyzer
{
    private const TIMEOUT_S = 120;

    public function __construct(
        #[Autowire(env: 'KALORIE_BRIDGE_SOCKET')] private string $socket,
        #[Autowire(env: 'KALORIE_MODEL')] private string $model,
        private LoggerInterface $logger,
    ) {
    }

    public function analyze(AnalysisRequest $request): MealAnalysis
    {
        $content = [];
        if ($request->photoJpeg !== null) {
            $content[] = ['type' => 'image', 'media_type' => 'image/jpeg', 'data' => base64_encode($request->photoJpeg)];
        }
        $content[] = ['type' => 'text', 'text' => MealPrompt::userText($request)];

        $answer = $this->post('/v1/structured', [
            'model' => $this->model,
            'system' => MealPrompt::SYSTEM,
            'schema' => MealPrompt::SCHEMA,
            'content' => $content,
            'timeout_s' => self::TIMEOUT_S - 10,
        ]);

        return MealAnalysis::fromOutput($answer['output'], (string) ($answer['model'] ?? $this->model));
    }

    public function suggest(SuggestionRequest $request): MealSuggestions
    {
        $answer = $this->post('/v1/structured', [
            'model' => $this->model,
            'system' => SuggestionPrompt::SYSTEM,
            'schema' => SuggestionPrompt::SCHEMA,
            'content' => [['type' => 'text', 'text' => SuggestionPrompt::userText($request)]],
            'timeout_s' => self::TIMEOUT_S - 10,
        ]);
        $suggestions = MealSuggestions::fromOutput($answer['output'], (string) ($answer['model'] ?? $this->model));
        if ($suggestions->suggestions === []) {
            $this->logger->warning('AI bridge: no usable suggestions');
            throw AnalysisFailed::noSuggestions();
        }
        return $suggestions;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $ch = curl_init('http://bridge' . $path);
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => $this->socket,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT_S,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        if ($raw === false) {
            $this->logger->error("AI bridge unreachable: $error");
            throw $errno === CURLE_OPERATION_TIMEDOUT ? AnalysisFailed::timeout() : AnalysisFailed::unavailable();
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->logger->error("AI bridge: invalid response (HTTP $status)");
            throw AnalysisFailed::failed();
        }
        if (($data['ok'] ?? false) !== true || !is_array($data['output'] ?? null)) {
            $type = $data['error']['type'] ?? 'unknown';
            $this->logger->warning("AI bridge error $type: " . ($data['error']['detail'] ?? ''));
            throw match ($type) {
                'busy' => AnalysisFailed::busy(),
                'usage_limit' => AnalysisFailed::usageLimit(),
                'timeout' => AnalysisFailed::timeout(),
                default => AnalysisFailed::failed(),
            };
        }
        return $data;
    }
}
