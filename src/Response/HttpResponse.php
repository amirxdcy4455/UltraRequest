<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Response;

class HttpResponse implements ResponseInterface
{
    private ?string $body;
    private array $headers = [];
    private string $rawHeaders;
    private int $statusCode;
    private array $info;

    public function __construct(?string $body, string $rawHeaders, int $statusCode, array $info = [])
    {
        $this->body = $body;
        $this->rawHeaders = $rawHeaders;
        $this->statusCode = $statusCode;
        $this->info = $info;
        $this->parseHeaders();
    }

    private function parseHeaders(): void
    {
        $blocks = preg_split('/\r?\n\r?\n/', trim($this->rawHeaders));
        $lastBlock = '';
        foreach ($blocks ?: [] as $block) {
            if (preg_match('/^HTTP\//i', ltrim($block))) $lastBlock = $block;
        }
        if ($lastBlock === '' && $this->rawHeaders !== '') $lastBlock = $this->rawHeaders;
        foreach (preg_split('/\r?\n/', $lastBlock) ?: [] as $line) {
            $position = strpos($line, ':');
            if ($position === false) continue;
            $name = strtolower(trim(substr($line, 0, $position)));
            $value = trim(substr($line, $position + 1));
            $this->headers[$name][] = $value;
        }
    }

    public function getBody(): ?string { return $this->body; }

    public function getHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $name => $values) {
            $last = end($values);
            $result[$name] = $last === false ? '' : $last;
        }
        return $result;
    }

    public function getHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];
        return $values === [] ? null : (string)end($values);
    }

    public function getHeaderValues(string $name): array { return $this->headers[strtolower($name)] ?? []; }
    public function getRawHeaders(): string { return $this->rawHeaders; }
    public function getStatusCode(): int { return $this->statusCode; }
    public function isSuccessful(): bool { return $this->statusCode >= 200 && $this->statusCode < 300; }
    public function isRedirect(): bool { return $this->statusCode >= 300 && $this->statusCode < 400; }
    public function isClientError(): bool { return $this->statusCode >= 400 && $this->statusCode < 500; }
    public function isServerError(): bool { return $this->statusCode >= 500 && $this->statusCode < 600; }

    public function getJson(bool $assoc = true, int $flags = 0): mixed
    {
        if ($this->body === null) return null;
        return json_decode($this->body, $assoc, 512, $flags);
    }

    public function getJsonOrFail(bool $assoc = true, int $flags = 0): mixed
    {
        if ($this->body === null) return null;
        return json_decode($this->body, $assoc, 512, $flags | JSON_THROW_ON_ERROR);
    }

    public function getJsonError(): ?string
    {
        if ($this->body === null) return null;
        json_decode($this->body);
        return json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg();
    }

    public function getInfo(): array { return $this->info; }
    public function __toString(): string { return $this->body ?? ''; }
}
