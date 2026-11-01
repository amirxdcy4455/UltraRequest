<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Client;

use Amirxd\UltraRequest\Request\RequestInterface;
use Countable;
use InvalidArgumentException;

/**
 * A named collection of HTTP requests to be executed by a Client.
 */
final class RequestPool implements Countable
{
    /** @var array<int|string, RequestInterface> */
    private array $requests = [];

    private int $nextGeneratedKey = 1;

    public function add(int|string $key, RequestInterface $request): self
    {
        if (array_key_exists($key, $this->requests)) {
            throw new InvalidArgumentException(sprintf('A request with key "%s" already exists.', (string)$key));
        }

        $this->requests[$key] = $request;

        return $this;
    }

    /**
     * Add a request with an automatically generated key.
     *
     * @return int|string The generated key.
     */
    public function addRequest(RequestInterface $request): int|string
    {
        do {
            $key = 'request_' . $this->nextGeneratedKey;
            $this->nextGeneratedKey++;
        } while (array_key_exists($key, $this->requests));

        $this->requests[$key] = $request;

        return $key;
    }

    /**
     * Add multiple requests while preserving their array keys.
     *
     * @param array<int|string, RequestInterface> $requests
     */
    public function addMany(array $requests): self
    {
        foreach ($requests as $key => $request) {
            if (!$request instanceof RequestInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Request at key "%s" must implement RequestInterface.',
                    (string)$key
                ));
            }

            if (array_key_exists($key, $this->requests)) {
                throw new InvalidArgumentException(sprintf(
                    'A request with key "%s" already exists.',
                    (string)$key
                ));
            }
        }

        foreach ($requests as $key => $request) {
            $this->requests[$key] = $request;
        }

        return $this;
    }

    /**
     * @return array<int|string, RequestInterface>
     */
    public function all(): array
    {
        return $this->requests;
    }

    public function get(int|string $key): ?RequestInterface
    {
        return $this->requests[$key] ?? null;
    }

    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->requests);
    }

    public function remove(int|string $key): self
    {
        unset($this->requests[$key]);

        return $this;
    }

    public function clear(): self
    {
        $this->requests = [];
        $this->nextGeneratedKey = 1;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->requests === [];
    }

    public function count(): int
    {
        return count($this->requests);
    }
}
