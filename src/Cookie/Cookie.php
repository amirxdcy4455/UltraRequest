<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Cookie;

class Cookie implements CookieInterface
{
    private string $name;
    private string $value;
    private ?int $expires = null;
    private string $path = '/';
    private ?string $domain = null;
    private bool $hostOnly = false;
    private bool $secure = false;
    private bool $httpOnly = false;
    private ?string $sameSite = null;

    public function __construct(string $name, string $value, array $options = [])
    {
        $this->name = $name;
        $this->value = $value;
        $this->expires = isset($options['expires']) ? (int)$options['expires'] : null;
        $this->path = (string)($options['path'] ?? '/');
        $domain = $options['domain'] ?? null;
        $this->domain = is_string($domain) && $domain !== '' ? strtolower(ltrim($domain, '.')) : null;
        $this->hostOnly = (bool)($options['hostOnly'] ?? false);
        $this->secure = (bool)($options['secure'] ?? false);
        $this->httpOnly = (bool)($options['httpOnly'] ?? false);
        $sameSite = $options['sameSite'] ?? null;
        $this->sameSite = is_string($sameSite) && $sameSite !== '' ? ucfirst(strtolower($sameSite)) : null;
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->name === '' || preg_match('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', $this->name)) {
            throw new \InvalidArgumentException('Invalid cookie name.');
        }
        $valueToValidate = $this->value;
        if (strlen($valueToValidate) >= 2 && $valueToValidate[0] === '"' && str_ends_with($valueToValidate, '"')) {
            $valueToValidate = substr($valueToValidate, 1, -1);
        }
        if (preg_match('/[^\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]/', $valueToValidate)) {
            throw new \InvalidArgumentException('Cookie values must use valid cookie-octet characters.');
        }
        if ($this->path === '' || $this->path[0] !== '/' || preg_match('/[\r\n;]/', $this->path)) $this->path = '/';
        if ($this->domain !== null) {
            $isIp = filter_var($this->domain, FILTER_VALIDATE_IP) !== false;
            if (!$isIp && (strlen($this->domain) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $this->domain))) {
                throw new \InvalidArgumentException('Invalid cookie domain.');
            }
            if (!$this->hostOnly && ($isIp || self::isPublicSuffixDomain($this->domain))) {
                throw new \InvalidArgumentException('Domain cookies cannot target an IP address or public suffix.');
            }
        }
        if ($this->sameSite !== null && !in_array($this->sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException('SameSite must be Lax, Strict, or None.');
        }
        if ($this->sameSite === 'None' && !$this->secure) throw new \InvalidArgumentException('SameSite=None cookies must be Secure.');
    }

    public static function isPublicSuffixDomain(string $domain): bool
    {
        static $knownSuffixes = [
            'com', 'net', 'org', 'edu', 'gov', 'mil', 'int', 'biz', 'info', 'name', 'pro',
            'io', 'dev', 'app', 'uk', 'de', 'fr', 'es', 'it', 'nl', 'be', 'ch', 'at', 'se',
            'no', 'dk', 'fi', 'pl', 'ru', 'cn', 'jp', 'kr', 'in', 'br', 'au', 'nz', 'ca',
            'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'sch.uk', 'com.au', 'net.au', 'org.au',
            'edu.au', 'gov.au', 'asn.au', 'id.au', 'co.jp', 'ne.jp', 'or.jp', 'ac.jp',
            'go.jp', 'co.in', 'firm.in', 'net.in', 'org.in', 'gen.in', 'ind.in',
            'com.br', 'net.br', 'org.br', 'com.cn', 'net.cn', 'org.cn', 'com.sg',
            'com.hk', 'com.mx', 'com.tr', 'com.tw', 'co.nz', 'net.nz', 'org.nz',
        ];
        return !str_contains($domain, '.') || in_array($domain, $knownSuffixes, true);
    }

    public function getName(): string { return $this->name; }
    public function getValue(): string { return $this->value; }
    public function getDomain(): ?string { return $this->domain; }
    public function getPath(): string { return $this->path; }
    public function getExpires(): ?int { return $this->expires; }
    public function isHostOnly(): bool { return $this->hostOnly; }
    public function isExpired(): bool { return $this->expires !== null && $this->expires <= time(); }
    public function isSecure(): bool { return $this->secure; }
    public function isHttpOnly(): bool { return $this->httpOnly; }

    public function matches(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) return false;
        $host = strtolower(trim($parts['host'], '[]'));
        $path = $parts['path'] ?? '/';
        $scheme = strtolower($parts['scheme'] ?? 'http');

        if ($this->isExpired() || ($this->secure && $scheme !== 'https')) return false;
        if ($this->domain === null) return false;
        if (!$this->hostOnly && self::isPublicSuffixDomain($this->domain)) return false;
        if ($this->hostOnly) {
            if ($host !== $this->domain) return false;
        } elseif (!$this->domainMatches($host)) {
            return false;
        }
        return $this->pathMatches($path);
    }

    private function domainMatches(string $host): bool
    {
        if ($host === $this->domain) return true;
        if (filter_var($host, FILTER_VALIDATE_IP) || filter_var($this->domain, FILTER_VALIDATE_IP)) return false;
        return str_ends_with($host, '.' . $this->domain);
    }

    private function pathMatches(string $requestPath): bool
    {
        if ($requestPath === $this->path) return true;
        if (!str_starts_with($requestPath, $this->path)) return false;
        if (str_ends_with($this->path, '/')) return true;
        return isset($requestPath[strlen($this->path)]) && $requestPath[strlen($this->path)] === '/';
    }

    public function __serialize(): array
    {
        return [
            'name' => $this->name, 'value' => $this->value, 'expires' => $this->expires,
            'path' => $this->path, 'domain' => $this->domain, 'hostOnly' => $this->hostOnly,
            'secure' => $this->secure, 'httpOnly' => $this->httpOnly, 'sameSite' => $this->sameSite,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->__construct((string)$data['name'], (string)$data['value'], $data);
    }

    public function __toString(): string
    {
        $result = $this->name . '=' . $this->value;
        if ($this->expires !== null) $result .= '; Expires=' . gmdate('D, d-M-Y H:i:s T', $this->expires);
        $result .= '; Path=' . $this->path;
        if ($this->domain !== null && !$this->hostOnly) $result .= '; Domain=' . $this->domain;
        if ($this->secure) $result .= '; Secure';
        if ($this->httpOnly) $result .= '; HttpOnly';
        if ($this->sameSite !== null) $result .= '; SameSite=' . $this->sameSite;
        return $result;
    }
}
