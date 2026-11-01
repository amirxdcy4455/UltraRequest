<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Cookie;

class CookieJar implements CookieJarInterface
{
    private array $cookies = [];
    private ?string $storagePath;

    public function __construct(?string $storagePath = 'Cookies.json', bool $fileLoad = false)
    {
        $this->storagePath = $storagePath;
        if ($fileLoad) $this->load();
    }

    public function setCookie(string $name, string $value, array $options = []): self
    {
        if (!isset($options['domain']) || !is_string($options['domain']) || $options['domain'] === '') {
            throw new \InvalidArgumentException('A domain is required. Use setCookieForUrl() to create a host-only cookie.');
        }
        $cookie = new Cookie($name, $value, $options);
        if ($cookie->getExpires() !== null && $cookie->getExpires() <= time()) {
            unset($this->cookies[$this->getCookieKey($cookie)]);
        } else {
            $this->cookies[$this->getCookieKey($cookie)] = $cookie;
        }
        $this->save();
        return $this;
    }

    public function setCookieForUrl(string $url, string $name, string $value, array $options = []): self
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('A valid HTTP or HTTPS URL is required.');
        }
        $options['domain'] = strtolower(trim($parts['host'], '[]'));
        $options['hostOnly'] = true;
        $options['path'] = $options['path'] ?? $this->defaultCookiePath($parts['path'] ?? '/');
        return $this->setCookie($name, $value, $options);
    }

    public function getCookie(string $name): ?CookieInterface
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie->getName() === $name && !$cookie->isExpired()) return $cookie;
        }
        return null;
    }

    public function getCookiesByName(string $name): array
    {
        return array_values(array_filter($this->cookies, static fn(Cookie $cookie): bool => $cookie->getName() === $name && !$cookie->isExpired()));
    }

    public function getAllCookies(): array
    {
        $valid = [];
        foreach ($this->cookies as $key => $cookie) {
            if (!$cookie->isExpired()) $valid[] = $cookie;
            else unset($this->cookies[$key]);
        }
        return $valid;
    }

    public function hasCookie(string $name): bool { return $this->getCookie($name) !== null; }

    public function removeCookie(string $name): self
    {
        foreach ($this->cookies as $key => $cookie) if ($cookie->getName() === $name) unset($this->cookies[$key]);
        $this->save();
        return $this;
    }

    public function clear(): self { $this->cookies = []; $this->save(); return $this; }

    public function fromString(string $cookieString, string $domain = '', string $requestPath = '/'): self
    {
        $parts = explode(';', $cookieString);
        $first = trim((string)array_shift($parts));
        $position = strpos($first, '=');
        if ($position === false) return $this;
        $name = trim(substr($first, 0, $position));
        $value = trim(substr($first, $position + 1));
        $options = ['domain' => $domain !== '' ? strtolower(trim($domain, '.')) : null, 'hostOnly' => true, 'path' => $this->defaultCookiePath($requestPath)];
        $maxAge = null;

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            [$attribute, $attributeValue] = array_pad(explode('=', $part, 2), 2, '');
            $attribute = strtolower(trim($attribute));
            $attributeValue = trim($attributeValue, " \t\n\r\0\x0B\"");
            switch ($attribute) {
                case 'expires':
                    $timestamp = strtotime($attributeValue);
                    if ($timestamp !== false) $options['expires'] = $timestamp;
                    break;
                case 'max-age':
                    if (preg_match('/^-?\d+$/', $attributeValue)) $maxAge = (int)$attributeValue;
                    break;
                case 'path':
                    if ($attributeValue !== '' && str_starts_with($attributeValue, '/')) $options['path'] = $attributeValue;
                    break;
                case 'domain':
                    $candidate = strtolower(ltrim($attributeValue, '.'));
                    $origin = strtolower((string)$options['domain']);
                    if ($candidate === '' || $origin === '' || !$this->domainAttributeMatches($origin, $candidate)) return $this;
                    $options['domain'] = $candidate;
                    $options['hostOnly'] = false;
                    break;
                case 'secure': $options['secure'] = true; break;
                case 'httponly': $options['httpOnly'] = true; break;
                case 'samesite': $options['sameSite'] = $attributeValue; break;
            }
        }
        if ($maxAge !== null) $options['expires'] = $maxAge <= 0 ? time() - 1 : time() + $maxAge;
        if (($options['domain'] ?? null) === null) return $this;
        try {
            return $this->setCookie($name, $value, $options);
        } catch (\InvalidArgumentException) {
            return $this;
        }
    }


    private function defaultCookiePath(string $requestPath): string
    {
        if ($requestPath === '' || $requestPath[0] !== '/') return '/';
        $lastSlash = strrpos($requestPath, '/');
        if ($lastSlash === false || $lastSlash === 0) return '/';
        return substr($requestPath, 0, $lastSlash);
    }

    private function domainAttributeMatches(string $host, string $domain): bool
    {
        if (Cookie::isPublicSuffixDomain($domain)) return false;
        if ($host === $domain) return true;
        if (filter_var($host, FILTER_VALIDATE_IP) || filter_var($domain, FILTER_VALIDATE_IP)) return false;
        return str_ends_with($host, '.' . $domain);
    }

    public function match(string $url): array
    {
        $matched = [];
        foreach ($this->getAllCookies() as $cookie) if ($cookie->matches($url)) $matched[] = $cookie;
        usort($matched, static fn(Cookie $a, Cookie $b): int => strlen($b->getPath()) <=> strlen($a->getPath()));
        return $matched;
    }

    public function toHeader(): string
    {
        return implode('; ', array_map(static fn(CookieInterface $cookie): string => $cookie->getName() . '=' . $cookie->getValue(), $this->getAllCookies()));
    }

    private function getCookieKey(CookieInterface $cookie): string
    {
        return $cookie->getName() . '@' . ($cookie->getDomain() ?? '*') . '@' . $cookie->getPath() . '@' . (($cookie instanceof Cookie && $cookie->isHostOnly()) ? 'host' : 'domain');
    }

    private function save(): void
    {
        if ($this->storagePath === null) return;
        $directory = dirname($this->storagePath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create cookie storage directory.');
        }
        $data = [];
        foreach ($this->getAllCookies() as $cookie) if ($cookie instanceof Cookie) $data[] = $cookie->__serialize();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $this->storagePath . '.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) === false) throw new \RuntimeException('Unable to write cookie storage file.');
        @chmod($temporary, 0600);
        if (DIRECTORY_SEPARATOR === '\\' && file_exists($this->storagePath)) @unlink($this->storagePath);
        if (!@rename($temporary, $this->storagePath)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to replace cookie storage file.');
        }
        @chmod($this->storagePath, 0600);
    }

    private function load(): void
    {
        if ($this->storagePath === null || !is_file($this->storagePath) || !is_readable($this->storagePath)) return;
        $content = file_get_contents($this->storagePath);
        if ($content === false || $content === '') return;
        try { $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return; }
        if (!is_array($data)) return;
        foreach ($data as $cookieData) {
            if (!is_array($cookieData) || !isset($cookieData['name'], $cookieData['value'])) continue;
            try {
                $cookie = new Cookie((string)$cookieData['name'], (string)$cookieData['value'], $cookieData);
                if (!$cookie->isExpired()) $this->cookies[$this->getCookieKey($cookie)] = $cookie;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
    }
}
