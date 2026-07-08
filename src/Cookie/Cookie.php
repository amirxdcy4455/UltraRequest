<?php

namespace Amirxd\UltraRequest\Cookie;

class Cookie implements CookieInterface {
    private string $name;
    private string $value;
    private ?int $expires = null;
    private string $path = '/';
    private ?string $domain = null;
    private bool $secure = false;
    private bool $httpOnly = false;
    private ?string $sameSite = null;
    
    public function __construct(string $name, string $value, array $options = []) {
        $this->name = $name;
        $this->value = $value;
        
        $this->expires = $options['expires'] ?? null;
        $this->path = $options['path'] ?? '/';
        $this->domain = $options['domain'] ?? null;
        $this->secure = $options['secure'] ?? false;
        $this->httpOnly = $options['httpOnly'] ?? false;
        $this->sameSite = $options['sameSite'] ?? null;
        
        $this->validate();
    }
    
    private function validate(): void {
        if (preg_match('/[=,; \t\r\n]/', $this->name)) {
            throw new \InvalidArgumentException("Invalid cookie name: {$this->name}");
        }
    }
    
    public function getName(): string {
        return $this->name;
    }
    
    public function getValue(): string {
        return $this->value;
    }
    
    public function getDomain(): ?string {
        return $this->domain;
    }
    
    public function getPath(): string {
        return $this->path;
    }
    
    public function isExpired(): bool {
        return $this->expires !== null && $this->expires < time();
    }
    
    public function isSecure(): bool {
        return $this->secure;
    }
    
    public function isHttpOnly(): bool {
        return $this->httpOnly;
    }
    
    public function matches(string $url): bool {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $scheme = $parts['scheme'] ?? 'http';
        
        if ($this->secure && $scheme !== 'https') {
            return false;
        }
        
        if ($this->domain !== null && !$this->domainMatches($host)) {
            return false;
        }
        
        if (!$this->pathMatches($path)) {
            return false;
        }
        
        return true;
    }
    
    private function domainMatches(string $host): bool {
        $domain = ltrim($this->domain, '.');
        
        if ($host === $domain) {
            return true;
        }
        
        if (str_ends_with($host, '.' . $domain)) {
            return !filter_var($host, FILTER_VALIDATE_IP);
        }
        
        return false;
    }
    
    private function pathMatches(string $path): bool {
        if ($this->path === '/') {
            return true;
        }
        
        return str_starts_with($path, $this->path);
    }
    
    public function __serialize(): array {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'expires' => $this->expires,
            'path' => $this->path,
            'domain' => $this->domain,
            'secure' => $this->secure,
            'httpOnly' => $this->httpOnly,
            'sameSite' => $this->sameSite
        ];
    }
    
    public function __unserialize(array $data): void {
        $this->name = $data['name'];
        $this->value = $data['value'];
        $this->expires = $data['expires'];
        $this->path = $data['path'];
        $this->domain = $data['domain'];
        $this->secure = $data['secure'];
        $this->httpOnly = $data['httpOnly'];
        $this->sameSite = $data['sameSite'];
    }
    
    public function __toString(): string {
        $str = $this->name . '=' . $this->value;
        
        if ($this->expires !== null) {
            $str .= '; Expires=' . gmdate('D, d-M-Y H:i:s T', $this->expires);
        }
        if ($this->path !== '') {
            $str .= '; Path=' . $this->path;
        }
        if ($this->domain !== null) {
            $str .= '; Domain=' . $this->domain;
        }
        if ($this->secure) {
            $str .= '; Secure';
        }
        if ($this->httpOnly) {
            $str .= '; HttpOnly';
        }
        if ($this->sameSite !== null) {
            $str .= '; SameSite=' . $this->sameSite;
        }
        
        return $str;
    }
}