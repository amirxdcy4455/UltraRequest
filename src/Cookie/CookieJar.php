<?php

namespace Amirxd\UltraRequest\Cookie;

class CookieJar implements CookieJarInterface {
    private array $cookies = [];
    private ?string $storagePath = null;
    
    public function __construct(?string $storagePath = "Cookies.json", bool $fileLoad = false) {
        $this->storagePath = $storagePath;
        if($fileLoad){
            $this->load();
        }
    }
    
    public function setCookie(string $name, string $value, array $options = []): self {
        $cookie = new Cookie($name, $value, $options);
        $key = $this->getCookieKey($cookie);
        $this->cookies[$key] = $cookie;
        $this->save();
        return $this;
    }
    
    public function getCookie(string $name): ?CookieInterface {
        foreach ($this->cookies as $cookie) {
            if ($cookie->getName() === $name && !$cookie->isExpired()) {
                return $cookie;
            }
        }
        return null;
    }
    
    public function getAllCookies(): array {
        $valid = [];
        foreach ($this->cookies as $cookie) {
            if (!$cookie->isExpired()) {
                $valid[] = $cookie;
            }
        }
        return $valid;
    }
    
    public function hasCookie(string $name): bool {
        return $this->getCookie($name) !== null;
    }
    
    public function removeCookie(string $name): self {
        foreach ($this->cookies as $key => $cookie) {
            if ($cookie->getName() === $name) {
                unset($this->cookies[$key]);
            }
        }
        $this->save();
        return $this;
    }
    
    public function clear(): self {
        $this->cookies = [];
        $this->save();
        return $this;
    }
    
    public function fromString(string $cookieString, string $domain = ''): self {
        $parts = explode(';', $cookieString);
        $first = array_shift($parts);
        
        $firstParts = explode('=', trim($first), 2);
        if (count($firstParts) !== 2) {
            return $this;
        }
        
        $name = trim($firstParts[0]);
        $value = trim($firstParts[1]);
        
        $options = ['domain' => $domain];
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (stripos($part, 'expires=') === 0) {
                $expiresStr = substr($part, 8);
                $timestamp = strtotime($expiresStr);
                if ($timestamp !== false && $timestamp > 0) {
                    $options['expires'] = $timestamp;
                }
            } elseif (stripos($part, 'path=') === 0) {
                $options['path'] = substr($part, 5);
            } elseif (stripos($part, 'domain=') === 0) {
                $options['domain'] = substr($part, 7);
            } elseif (strcasecmp($part, 'secure') === 0) {
                $options['secure'] = true;
            } elseif (strcasecmp($part, 'httponly') === 0) {
                $options['httpOnly'] = true;
            } elseif (stripos($part, 'samesite=') === 0) {
                $options['sameSite'] = substr($part, 9);
            }
        }
        
        return $this->setCookie($name, $value, $options);
    }
    
    public function match(string $url): array {
        $matched = [];
        foreach ($this->cookies as $cookie) {
            if (!$cookie->isExpired() && $cookie->matches($url)) {
                $matched[] = $cookie;
            }
        }
        return $matched;
    }
    
    public function toHeader(): string {
        $validCookies = $this->getAllCookies();
        $parts = array_map(function($c) {
            return $c->getName() . '=' . $c->getValue();
        }, $validCookies);
        return implode('; ', $parts);
    }
    
    private function getCookieKey(CookieInterface $cookie): string {
        return $cookie->getName() . '@' . ($cookie->getDomain() ?? '*') . $cookie->getPath();
    }
    
    private function save(): void {
        if ($this->storagePath === null) {
            return;
        }
        
        $data = [];
        foreach ($this->getAllCookies() as $cookie) {
            if (!$cookie instanceof Cookie) continue;
            $cdata = $cookie->__serialize();
            $data[] = $cdata; 
        }
        
        file_put_contents($this->storagePath, json_encode($data, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR), LOCK_EX);
    }
    
    private function load(): void {
        if ($this->storagePath === null || !file_exists($this->storagePath)) {
            return;
        }
        
        $content = file_get_contents($this->storagePath);
        if ($content === false) {
            return;
        }
        
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }
        
        $this->cookies = [];
        foreach ($data as $cookieData) {
            if (!isset($cookieData['name'], $cookieData['value'])) {
                continue;
            }
            
            $cookie = new Cookie($cookieData['name'], $cookieData['value'], $cookieData);
            $key = $this->getCookieKey($cookie);
            $this->cookies[$key] = $cookie;
        }
    }
}