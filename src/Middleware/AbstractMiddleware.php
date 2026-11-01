<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Middleware;

use Amirxd\UltraRequest\Response\HttpResponse;
use Throwable;

/**
 * Convenience base class for middleware that only needs selected lifecycle hooks.
 */
abstract class AbstractMiddleware implements MiddlewareInterface
{
    public function before(RequestContext $context): void
    {
    }

    public function after(RequestContext $context, HttpResponse $response): HttpResponse
    {
        return $response;
    }

    public function onError(RequestContext $context, Throwable $error): HttpResponse|Throwable
    {
        return $error;
    }
}
