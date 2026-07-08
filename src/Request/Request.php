<?php

namespace Amirxd\UltraRequest\Request;

class Request implements RequestInterface {
    public string $method = 'GET';
    public string $url;
    public array $headers = [];
    public mixed $body = null;
    public bool $followRedirects = true;
    public ?int $maxRedirects = 10;
    public array $queryParams = [];
    public ?array $urlMethod = null;
    
    public function __construct(string $method, string $url) {
        $this->method = strtoupper($method);
        $this->url = $url;
    }
    
    public static function get(string $url): self {
        return new self('GET', $url);
    }
    
    public static function post(string $url): self {
        return new self('POST', $url);
    }
    
    public static function put(string $url): self {
        return new self('PUT', $url);
    }
    
    public static function delete(string $url): self {
        return new self('DELETE', $url);
    }
    
    public static function patch(string $url): self {
        return new self('PATCH', $url);
    }
    
    public static function head(string $url): self {
        return new self('HEAD', $url);
    }
    
    public function withHeader(string $name, string $value): self {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }
    
    public function withHeaders(array $headers): self {
        $clone = clone $this;
        $clone->headers = array_merge($clone->headers, $headers);
        return $clone;
    }
    
    public function withBody($body): self {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }
    
    public function withJson(array $data): self {
        $clone = clone $this;
        $clone->headers['Content-Type'] = 'application/json';
        $clone->body = json_encode($data);
        return $clone;
    }
    
    public function withQueryParams(array $params): self {
        $clone = clone $this;
        $clone->queryParams = array_merge($clone->queryParams, $params);
        return $clone;
    }
    
    public function withoutRedirects(): self {
        $clone = clone $this;
        $clone->followRedirects = false;
        return $clone;
    }
    
    public function withMaxRedirects(int $max): self {
        $clone = clone $this;
        $clone->maxRedirects = $max;
        return $clone;
    }
    
    public function withUrlMethod(string $name, string $value): self {
        $clone = clone $this;
        $clone->urlMethod = ["name" => $name, "value" => $value];
        return $clone;
    }
    
    public function getMethod(): string {
        return $this->method;
    }
    
    public function getUrl(): string {
        $allParams = $this->queryParams;
        
        $urlMethod = $this->getUrlMethod();
        if ($urlMethod !== null && isset($urlMethod['name'], $urlMethod['value'])) {
            $allParams[$urlMethod['name']] = $urlMethod['value'];
        }
        
        if (empty($allParams)) {
            return $this->url;
        }
        
        $parsed = parse_url($this->url);
        
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        
        $existing = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $existing);
        }
        
        $merged = array_merge($existing, $allParams);
        $newQuery = http_build_query($merged);
        
        $scheme = $parsed['scheme'] ?? '';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        
        if (!empty($host)) {
            $base = '';
            if (!empty($scheme)) {
                $base = $scheme . '://';
            }
            $base .= $host . $port . $path;
            return $base . '?' . $newQuery . $fragment;
        }
        
        $separator = strpos($this->url, '?') === false ? '?' : '&';
        $queryPart = $newQuery !== '' ? $separator . $newQuery : '';
        
        $urlWithoutFragment = preg_replace('/#.*$/', '', $this->url);
        
        return $urlWithoutFragment . $queryPart . $fragment;
    }
    
    public function getHeaders(): array {
        return $this->headers;
    }

    public function getUrlMethod(): ?array {
        return $this->urlMethod;
    }
    
    public function getBody() {
        return $this->body;
    }
    
    public function followsRedirects(): bool {
        return $this->followRedirects;
    }
    
    public function getMaxRedirects(): ?int {
        return $this->maxRedirects;
    }
}