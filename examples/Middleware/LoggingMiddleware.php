<?php

declare(strict_types=1);

namespace Example\Middleware;

use Amirxd\UltraRequest\Middleware\AbstractMiddleware;
use Amirxd\UltraRequest\Middleware\RequestContext;
use Amirxd\UltraRequest\Response\HttpResponse;
use Throwable;

/**
 * Minimal logger example. The callback receives an event name and context data.
 */
final class LoggingMiddleware extends AbstractMiddleware
{
    private \Closure $logger;

    public function __construct(callable $logger)
    {
        $this->logger = \Closure::fromCallable($logger);
    }

    public function before(RequestContext $context): void
    {
        $request = $context->getRequest();

        ($this->logger)('request.started', [
            'key' => $context->getKey(),
            'method' => $request->getMethod(),
            'url' => $this->sanitizeUrl($request->getUrl()),
        ]);
    }

    public function after(RequestContext $context, HttpResponse $response): HttpResponse
    {
        ($this->logger)('request.completed', [
            'key' => $context->getKey(),
            'status' => $response->getStatusCode(),
            'duration' => $response->getInfo()['total_time'] ?? null,
        ]);

        return $response;
    }

    private function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '[invalid-url]';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return $scheme . $host . $port . $path;
    }

    public function onError(RequestContext $context, Throwable $error): HttpResponse|Throwable
    {
        ($this->logger)('request.failed', [
            'key' => $context->getKey(),
            'exception' => get_class($error),
            'message' => $error->getMessage(),
        ]);

        return $error;
    }
}
