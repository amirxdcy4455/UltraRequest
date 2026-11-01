<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Middleware;

use Amirxd\UltraRequest\Request\RequestInterface;
use Amirxd\UltraRequest\Response\HttpResponse;
use LogicException;

/**
 * Per-request state shared by all middleware in one logical HTTP operation.
 *
 * The same context survives redirect hops and is also used by concurrent
 * requests, so middleware can safely store request-local data in attributes.
 */
final class RequestContext
{
    private RequestInterface $originalRequest;
    private RequestInterface $request;

    /** @var int|string|null */
    private int|string|null $key;

    private array $attributes = [];
    private ?HttpResponse $shortCircuitResponse = null;

    /** @var MiddlewareInterface[] */
    private array $enteredMiddlewares = [];

    private bool $pipelineStarted = false;

    /** @param int|string|null $key */
    public function __construct(RequestInterface $request, int|string|null $key = null)
    {
        $this->originalRequest = $request;
        $this->request = $request;
        $this->key = $key;
    }

    public function getOriginalRequest(): RequestInterface
    {
        return $this->originalRequest;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function setRequest(RequestInterface $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getKey(): int|string|null
    {
        return $this->key;
    }

    public function setAttribute(string $name, mixed $value): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Context attribute name cannot be empty.');
        }

        $this->attributes[$name] = $value;

        return $this;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes)
            ? $this->attributes[$name]
            : $default;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function removeAttribute(string $name): self
    {
        unset($this->attributes[$name]);

        return $this;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Provide a response without opening a network connection.
     *
     * This is typically used by cache middleware.
     */
    public function respond(HttpResponse $response): self
    {
        $this->shortCircuitResponse = $response;

        return $this;
    }

    public function getShortCircuitResponse(): ?HttpResponse
    {
        return $this->shortCircuitResponse;
    }

    public function hasShortCircuitResponse(): bool
    {
        return $this->shortCircuitResponse !== null;
    }

    /** @internal */
    public function startPipeline(): void
    {
        if ($this->pipelineStarted) {
            throw new LogicException('The middleware pipeline has already been started for this request context.');
        }

        $this->pipelineStarted = true;
    }

    /** @internal */
    public function enterMiddleware(MiddlewareInterface $middleware): void
    {
        $this->enteredMiddlewares[] = $middleware;
    }

    /**
     * @return MiddlewareInterface[]
     * @internal
     */
    public function getEnteredMiddlewares(): array
    {
        return $this->enteredMiddlewares;
    }
}
