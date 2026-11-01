<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Middleware;

use Amirxd\UltraRequest\Response\HttpResponse;
use Throwable;

/**
 * Defines the lifecycle hooks supported by the Ultra Request middleware pipeline.
 *
 * Middleware runs in registration order before a request is sent. Response and
 * error hooks run in reverse order, following the usual onion-style ordering.
 */
interface MiddlewareInterface
{
    /**
     * Inspect or modify the outgoing request.
     *
     * Call $context->respond($response) to short-circuit the transport and
     * provide a response directly.
     */
    public function before(RequestContext $context): void;

    /**
     * Inspect or transform a successfully received response.
     */
    public function after(RequestContext $context, HttpResponse $response): HttpResponse;

    /**
     * Inspect or transform an exception.
     *
     * Return the same or a replacement Throwable to keep failing, or return an
     * HttpResponse to recover the request. A recovered response bypasses the
     * remaining response hooks and is returned to the caller.
     */
    public function onError(RequestContext $context, Throwable $error): HttpResponse|Throwable;
}
