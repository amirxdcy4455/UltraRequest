<?php

namespace Amirxd\UltraRequest\Proxy;

class Proxy implements ProxyInterface {
    private string $host;
    private int $port;
    private ?string $username = null;
    private ?string $password = null;
    private string $type = 'http';
    
    public function __construct(string $proxyString) {
        $this->parse($proxyString);
    }
    
    private function parse(string $proxyString): void {
        if (!preg_match('/^[a-zA-Z]+:\/\//', $proxyString)) {
            $proxyString = 'http://' . $proxyString;
        }
        
        $parts = parse_url($proxyString);
        
        $this->type = strtolower($parts['scheme'] ?? 'http');
        $this->host = $parts['host'] ?? '';
        $this->port = (int)($parts['port'] ?? $this->getDefaultPort());
        
        if (isset($parts['user'])) {
            $this->username = urldecode($parts['user']);
        }
        if (isset($parts['pass'])) {
            $this->password = urldecode($parts['pass']);
        }
        
        $this->validate();
    }
    
    private function getDefaultPort(): int {
        return match($this->type) {
            'socks5', 'socks4' => 1080,
            'https' => 443,
            default => 8080
        };
    }
    
    private function validate(): void {
        if (empty($this->host)) {
            throw new \InvalidArgumentException("Proxy host cannot be empty");
        }
        
        if ($this->port < 1 || $this->port > 65535) {
            throw new \InvalidArgumentException("Invalid proxy port: {$this->port}");
        }
        
        $validTypes = ['http', 'https', 'socks4', 'socks5'];
        if (!in_array($this->type, $validTypes)) {
            throw new \InvalidArgumentException("Unsupported proxy type: {$this->type}");
        }
    }
    
    public function withAuth(string $username, string $password): self {
        $clone = clone $this;
        $clone->username = $username;
        $clone->password = $password;
        return $clone;
    }
    
    public function getCurlOptions(): array {
        $options = [
            CURLOPT_PROXY => $this->host,
            CURLOPT_PROXYPORT => $this->port,
            CURLOPT_PROXYTYPE => $this->getCurlProxyType()
        ];
        
        if ($this->username !== null && $this->password !== null) {
            $options[CURLOPT_PROXYUSERPWD] = $this->username . ':' . $this->password;
        }
        
        return $options;
    }
    
    private function getCurlProxyType(): int {
        return match($this->type) {
            'http' => CURLPROXY_HTTP,
            'https' => CURLPROXY_HTTPS,
            'socks4' => CURLPROXY_SOCKS4,
            'socks5' => CURLPROXY_SOCKS5,
            default => CURLPROXY_HTTP,
        };
    }
    
    public function getType(): string {
        return $this->type;
    }

    public function getHost(): string {
        return $this->host;
    }

    public function getPort(): int {
        return $this->port;
    }
    
    public function __toString(): string {
        $auth = '';
        if ($this->username !== null) {
            $auth = $this->username;
            if ($this->password !== null) {
                $auth .= ':' . $this->password;
            }
            $auth .= '@';
        }
        return $this->type . '://' . $auth . $this->host . ':' . $this->port;
    }
}