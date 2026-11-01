<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Request;

class Request implements RequestInterface
{
    public string $method = 'GET';
    public string $url;
    public array $headers = [];
    public mixed $body = null;
    public bool $followRedirects = true;
    public ?int $maxRedirects = 10;
    public array $queryParams = [];
    public ?array $urlMethod = null;

    public function __construct(string $method, string $url)
    {
        $method = strtoupper(trim($method));
        if ($method === '' || !preg_match('/^[!#$%&\'*+.^_`|~0-9A-Z-]+$/', $method)) {
            throw new \InvalidArgumentException('Invalid HTTP method.');
        }
        self::validateUrl($url);
        $this->method = $method;
        $this->url = $url;
    }

    public static function get(string $url): self { return new self('GET', $url); }
    public static function post(string $url): self { return new self('POST', $url); }
    public static function put(string $url): self { return new self('PUT', $url); }
    public static function delete(string $url): self { return new self('DELETE', $url); }
    public static function patch(string $url): self { return new self('PATCH', $url); }
    public static function head(string $url): self { return new self('HEAD', $url); }

    private static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException('URL must be an absolute HTTP or HTTPS URL.');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only HTTP and HTTPS URLs are supported.');
        }
        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            throw new \InvalidArgumentException('URL port must be between 1 and 65535.');
        }
        if (preg_match('/[\r\n\x00-\x20]/', $url)) {
            throw new \InvalidArgumentException('URL contains invalid whitespace or control characters.');
        }
    }

    public function withHeader(string $name, string $value): self
    {
        $this->validateHeader($name, $value);
        $existing = $this->findHeaderName($name);
        $this->headers[$existing ?? $name] = $value;
        return $this;
    }

    public function setHeader(string $name, string $value): self { return $this->withHeader($name, $value); }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_numeric($value))) {
                throw new \InvalidArgumentException('Headers must be an associative array of string values.');
            }
            $this->withHeader($name, (string)$value);
        }
        return $this;
    }

    public function setHeaders(array $headers): self { return $this->withHeaders($headers); }

    private function validateHeader(string $name, string $value): void
    {
        if ($name === '' || preg_match('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', $name)) {
            throw new \InvalidArgumentException('Invalid HTTP header name.');
        }
        if (preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException('HTTP header values cannot contain CR or LF.');
        }
    }

    private function findHeaderName(string $name): ?string
    {
        foreach ($this->headers as $existing => $_) {
            if (strcasecmp((string)$existing, $name) === 0) return (string)$existing;
        }
        return null;
    }

    public function withoutHeader(string $name): self
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp((string)$existing, $name) === 0) unset($this->headers[$existing]);
        }
        return $this;
    }

    public function withBody(mixed $body): self { $this->body = $body; return $this; }
    public function setBody(mixed $body): self { return $this->withBody($body); }

    public function withJson(array|object $data, int $flags = 0): self
    {
        $this->withHeader('Content-Type', 'application/json');
        $this->body = json_encode($data, $flags | JSON_THROW_ON_ERROR);
        return $this;
    }

    public function setJson(array|object $data, int $flags = 0): self { return $this->withJson($data, $flags); }

    public function withQueryParams(array $params): self
    {
        $this->queryParams = array_replace($this->queryParams, $params);
        return $this;
    }

    public function setQueryParams(array $params): self { return $this->withQueryParams($params); }

    public function withoutRedirects(): self { $this->followRedirects = false; return $this; }
    public function withRedirects(bool $follow = true): self { $this->followRedirects = $follow; return $this; }
    public function setFollowRedirects(bool $follow): self { return $this->withRedirects($follow); }

    public function withMaxRedirects(int $max): self
    {
        if ($max < 0) throw new \InvalidArgumentException('Maximum redirects cannot be negative.');
        $this->maxRedirects = $max;
        return $this;
    }

    public function setMaxRedirects(int $max): self { return $this->withMaxRedirects($max); }

    public function withUrlMethod(string $name, string $value): self
    {
        if ($name === '') throw new \InvalidArgumentException('URL parameter name cannot be empty.');
        $this->urlMethod = ['name' => $name, 'value' => $value];
        return $this;
    }

    public function setUrlMethod(string $name, string $value): self { return $this->withUrlMethod($name, $value); }

    public function getMethod(): string { return $this->method; }
    public function getHeaders(): array { return $this->headers; }
    public function getBody(): mixed { return $this->body; }
    public function followsRedirects(): bool { return $this->followRedirects; }
    public function getMaxRedirects(): ?int { return $this->maxRedirects; }
    public function getUrlMethod(): ?array { return $this->urlMethod; }
    public function getQueryParams(): array { return $this->queryParams; }

    public function getUrl(): string
    {
        $params = $this->queryParams;
        if ($this->urlMethod !== null) {
            $params[$this->urlMethod['name']] = $this->urlMethod['value'];
        }
        if ($params === []) return $this->url;

        $parts = parse_url($this->url);
        if ($parts === false) throw new \InvalidArgumentException('Invalid URL.');
        $replacedKeys = array_fill_keys(array_map('strval', array_keys($params)), true);
        $queryParts = [];
        foreach (explode('&', (string)($parts['query'] ?? '')) as $existingPart) {
            if ($existingPart === '') continue;
            $rawKey = explode('=', $existingPart, 2)[0];
            $decodedKey = urldecode($rawKey);
            if (isset($replacedKeys[$decodedKey])) continue;
            $queryParts[] = $existingPart;
        }
        $newQuery = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($newQuery !== '') $queryParts[] = $newQuery;
        $query = implode('&', $queryParts);

        $scheme = $parts['scheme'] . '://';
        $userInfo = '';
        if (isset($parts['user'])) {
            $userInfo = $parts['user'];
            if (isset($parts['pass'])) $userInfo .= ':' . $parts['pass'];
            $userInfo .= '@';
        }
        $host = $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) $host = '[' . $host . ']';
        $authority = $userInfo . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $scheme . $authority . $path . ($query !== '' ? '?' . $query : '') . $fragment;
    }
}
