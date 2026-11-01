<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Middleware;

use Amirxd\UltraRequest\Response\HttpResponse;
use Throwable;

/**
 * Executes middleware lifecycle hooks for one or more independent requests.
 *
 * The pipeline object is stateless between requests. Each RequestContext stores
 * its own entered middleware stack, which makes one pipeline safe to reuse for
 * all members of a concurrent request batch.
 */
final class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private array $middlewares;

    /**
     * @param MiddlewareInterface[] $middlewares
     */
    public function __construct(array $middlewares = [])
    {
        foreach ($middlewares as $middleware) {
            if (!$middleware instanceof MiddlewareInterface) {
                throw new \InvalidArgumentException('Every middleware must implement MiddlewareInterface.');
            }
        }

        $this->middlewares = array_values($middlewares);
    }

    /**
     * Run outgoing-request hooks.
     *
     * @return HttpResponse|null A short-circuit response, if one was supplied.
     */
    public function prepare(RequestContext $context): ?HttpResponse
    {
        $context->startPipeline();

        foreach ($this->middlewares as $middleware) {
            // Add it before invoking before(), so its onError() hook can handle
            // an exception raised by its own request preparation.
            $context->enterMiddleware($middleware);
            $middleware->before($context);

            if ($context->hasShortCircuitResponse()) {
                return $context->getShortCircuitResponse();
            }
        }

        return null;
    }

    /**
     * Run response hooks in reverse registration order.
     */
    public function processResponse(RequestContext $context, HttpResponse $response): HttpResponse
    {
        $middlewares = array_reverse($context->getEnteredMiddlewares());

        foreach ($middlewares as $middleware) {
            $response = $middleware->after($context, $response);

            if (!$response instanceof HttpResponse) {
                throw new \UnexpectedValueException(sprintf(
                    'Middleware %s::after() must return an HttpResponse instance.',
                    get_class($middleware)
                ));
            }
        }

        return $response;
    }

    /**
     * Run error hooks in reverse registration order.
     *
     * A middleware may recover by returning an HttpResponse. Otherwise it must
     * return the same or a replacement Throwable.
     */
    public function processError(RequestContext $context, Throwable $error): HttpResponse|Throwable
    {
        $middlewares = array_reverse($context->getEnteredMiddlewares());
        $currentError = $error;

        foreach ($middlewares as $middleware) {
            try {
                $result = $middleware->onError($context, $currentError);
            } catch (Throwable $middlewareError) {
                $currentError = $middlewareError;
                continue;
            }

            if ($result instanceof HttpResponse) {
                return $result;
            }

            if ($result instanceof Throwable) {
                $currentError = $result;
                continue;
            }

            $currentError = new \UnexpectedValueException(sprintf(
                'Middleware %s::onError() must return HttpResponse or Throwable.',
                get_class($middleware)
            ));
        }

        return $currentError;
    }
}
