<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Proxy;

class Proxy implements ProxyInterface
{
    private string $host;
    private int $port;
    private ?string $username = null;
    private ?string $password = null;
    private string $type = 'http';

    public function __construct(string $proxyString) { $this->parse($proxyString); }

    private function parse(string $proxyString): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $proxyString)) $proxyString = 'http://' . $proxyString;
        $parts = parse_url($proxyString);
        if ($parts === false) throw new \InvalidArgumentException('Invalid proxy URL.');
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/' || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Proxy URL cannot contain a path, query, or fragment.');
        }
        $this->type = strtolower($parts['scheme'] ?? 'http');
        $this->host = trim((string)($parts['host'] ?? ''), '[]');
        $this->port = (int)($parts['port'] ?? $this->getDefaultPort());
        if (isset($parts['user'])) $this->username = rawurldecode($parts['user']);
        if (isset($parts['pass'])) $this->password = rawurldecode($parts['pass']);
        $this->validate();
    }

    private function getDefaultPort(): int
    {
        return match ($this->type) { 'socks4', 'socks5' => 1080, 'https' => 443, default => 8080 };
    }

    private function validate(): void
    {
        if ($this->host === '' || preg_match('/[\s\r\n]/', $this->host)) throw new \InvalidArgumentException('Invalid proxy host.');
        if ($this->username !== null && (str_contains($this->username, ':') || preg_match('/[\r\n]/', $this->username))) {
            throw new \InvalidArgumentException('Invalid proxy username.');
        }
        if ($this->password !== null && preg_match('/[\r\n]/', $this->password)) {
            throw new \InvalidArgumentException('Invalid proxy password.');
        }
        if ($this->port < 1 || $this->port > 65535) throw new \InvalidArgumentException('Invalid proxy port.');
        if (!in_array($this->type, ['http', 'https', 'socks4', 'socks5'], true)) throw new \InvalidArgumentException('Unsupported proxy type: ' . $this->type);
    }

    public function withAuth(string $username, string $password): self
    {
        if (str_contains($username, ':') || preg_match('/[\r\n]/', $username) || preg_match('/[\r\n]/', $password)) {
            throw new \InvalidArgumentException('Invalid proxy credentials.');
        }
        $this->username = $username;
        $this->password = $password;
        return $this;
    }

    public function getCurlOptions(): array
    {
        $proxyHost = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $options = [CURLOPT_PROXY => $proxyHost, CURLOPT_PROXYPORT => $this->port, CURLOPT_PROXYTYPE => $this->getCurlProxyType()];
        if ($this->username !== null && $this->password !== null) $options[CURLOPT_PROXYUSERPWD] = $this->username . ':' . $this->password;
        return $options;
    }

    private function getCurlProxyType(): int
    {
        return match ($this->type) {
            'http' => CURLPROXY_HTTP,
            'https' => defined('CURLPROXY_HTTPS') ? constant('CURLPROXY_HTTPS') : throw new \RuntimeException('HTTPS proxy support is not available in this libcurl build.'),
            'socks4' => CURLPROXY_SOCKS4,
            'socks5' => CURLPROXY_SOCKS5,
        };
    }

    public function getType(): string { return $this->type; }
    public function getHost(): string { return $this->host; }
    public function getPort(): int { return $this->port; }

    public function __toString(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        return $this->type . '://' . $host . ':' . $this->port;
    }
}
