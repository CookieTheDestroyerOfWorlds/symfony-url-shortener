<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class JsonExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 0]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // Only handle requests that expect JSON (API routes or explicit Accept header)
        $isApiRequest = str_starts_with($request->getPathInfo(), '/api')
            || str_contains($request->headers->get('Accept', ''), 'application/json');

        if (!$isApiRequest) {
            return;
        }

        $exception = $event->getThrowable();

        [$statusCode, $message] = $this->resolveException($exception);

        $event->setResponse(new JsonResponse(['error' => $message], $statusCode));
    }

    /** @return array{0: int, 1: string} */
    private function resolveException(\Throwable $exception): array
    {
        if ($exception instanceof HttpExceptionInterface) {
            return [
                $exception->getStatusCode(),
                $exception->getMessage() ?: Response::$statusTexts[$exception->getStatusCode()] ?? 'Error',
            ];
        }

        // Don't leak internals in production — the kernel will log the real cause
        return [Response::HTTP_INTERNAL_SERVER_ERROR, 'An unexpected error occurred.'];
    }
}
