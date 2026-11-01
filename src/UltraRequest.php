<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest;

use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Client\RequestPool;
use Amirxd\UltraRequest\Request\Request;
use Amirxd\UltraRequest\Request\RequestInterface;
use Amirxd\UltraRequest\Response\HttpResponse;
use Amirxd\UltraRequest\Middleware\MiddlewareInterface;

class UltraRequest
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->client->addMiddleware($middleware);

        return $this;
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        return $this->addMiddleware($middleware);
    }

    public function prependMiddleware(MiddlewareInterface $middleware): self
    {
        $this->client->prependMiddleware($middleware);

        return $this;
    }

    public function removeMiddleware(MiddlewareInterface|string $middleware): self
    {
        $this->client->removeMiddleware($middleware);

        return $this;
    }

    public function clearMiddlewares(): self
    {
        $this->client->clearMiddlewares();

        return $this;
    }

    public function setClient(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @param array<int|string, RequestInterface>|RequestPool $requests
     * @return array<int|string, HttpResponse|\\Throwable>
     */
    public function sendMany(array|RequestPool $requests, int $concurrency = 5): array
    {
        return $this->client->sendMany($requests, $concurrency);
    }

    /**
     * @return array<int|string, HttpResponse|\\Throwable>
     */
    public function sendPool(RequestPool $pool, int $concurrency = 5): array
    {
        return $this->client->sendPool($pool, $concurrency);
    }

    public function get(string $url, array $query = [], array $headers = []): HttpResponse
    {
        $request = Request::get($url);
        $request->withQueryParams($query);
        $request->withHeaders($headers);

        return $this->client->send($request);
    }

    public function post(string $url, mixed $body = null, array $headers = []): HttpResponse
    {
        $request = Request::post($url);
        $request->withHeaders($headers);

        if ($body !== null) {
            $request->withBody($body);
        }

        return $this->client->send($request);
    }

    public function put(string $url, mixed $body = null, array $headers = []): HttpResponse
    {
        $request = Request::put($url);
        $request->withHeaders($headers);

        if ($body !== null) {
            $request->withBody($body);
        }

        return $this->client->send($request);
    }

    public function patch(string $url, mixed $body = null, array $headers = []): HttpResponse
    {
        $request = Request::patch($url);
        $request->withHeaders($headers);

        if ($body !== null) {
            $request->withBody($body);
        }

        return $this->client->send($request);
    }

    public function delete(string $url, array $headers = []): HttpResponse
    {
        $request = Request::delete($url);
        $request->withHeaders($headers);

        return $this->client->send($request);
    }

    public function head(string $url, array $headers = []): HttpResponse
    {
        $request = Request::head($url);
        $request->withHeaders($headers);

        return $this->client->send($request);
    }
}
