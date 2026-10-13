<?php

namespace App\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/** Every error under /api comes back as JSON, never as an HTML error page. */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -10)]
final class ApiExceptionListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof ApiError) {
            $event->setResponse(ApiError::response($e->getStatusCode(), $e->errorCode, $e->getMessage(), $e->getHeaders()));
            return;
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = match ($status) {
                404 => 'Nie znaleziono.',
                405 => 'Niedozwolona metoda.',
                403 => 'Brak dostępu.',
                401 => 'Zaloguj się ponownie.',
                413 => 'Plik jest za duży.',
                default => 'Błędne żądanie.',
            };
            $event->setResponse(ApiError::response($status, 'http_' . $status, $message, $e->getHeaders()));
            return;
        }
        $this->logger->error('Unhandled API error: ' . $e->getMessage(), ['exception' => $e]);
        $event->setResponse(ApiError::response(500, 'server_error', 'Coś poszło nie tak po stronie serwera.'));
    }
}
