<?php

namespace Amirxd\UltraRequest\Response;

class HttpResponse implements ResponseInterface {
    private ?string $body;
    private array $headers = [];
    private string $rawHeaders;
    private int $statusCode;
    private array $info;
    
    public function __construct(?string $body, string $rawHeaders, int $statusCode, array $info = []) {
        $this->body = $body;
        $this->rawHeaders = $rawHeaders;
        $this->statusCode = $statusCode;
        $this->info = $info;
        $this->parseHeaders();
    }
    
    private function parseHeaders(): void {
        $headers = explode("\r\n", $this->rawHeaders);
        foreach ($headers as $header) {
            $header = trim($header);
            if (empty($header)) continue;
            
            $pos = strpos($header, ':');
            if ($pos !== false) {
                $key = trim(substr($header, 0, $pos));
                $value = ltrim(substr($header, $pos + 1));
                $this->headers[strtolower($key)] = $value;
            }
        }
    }
    
    public function getBody(): ?string {
        return $this->body;
    }
    
    public function getHeaders(): array {
        return $this->headers;
    }
    
    public function getHeader(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }
    
    public function getStatusCode(): int {
        return $this->statusCode;
    }
    
    public function isSuccessful(): bool {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
    
    public function isRedirect(): bool {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }
    
    public function isClientError(): bool {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }
    
    public function isServerError(): bool {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }
    
    public function getJson(bool $assoc = true) {
        if ($this->body === null) {
            return null;
        }
        return json_decode($this->body, $assoc);
    }
    
    public function getInfo(): array {
        return $this->info;
    }
    
    public function __toString(): string {
        return $this->body ?? '';
    }
}