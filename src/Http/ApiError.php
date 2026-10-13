<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An error the API reports as {"error": {"code", "message"}}; the message is Polish and
 * meant to be shown to the user as is.
 */
class ApiError extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, string $message, array $headers = [])
    {
        parent::__construct($status, $message, null, $headers);
    }

    public static function response(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }

    public static function invalid(string $message): self
    {
        return new self(422, 'invalid', $message);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'Nie znaleziono.');
    }
}
