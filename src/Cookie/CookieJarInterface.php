<?php

namespace Amirxd\UltraRequest\Cookie;

interface CookieJarInterface {
    public function setCookie(string $name, string $value, array $options = []): self;
    public function getCookie(string $name): ?CookieInterface;
    public function getAllCookies(): array;
    public function hasCookie(string $name): bool;
    public function removeCookie(string $name): self;
    public function fromString(string $cookieString, string $domain = ''): self;
    public function match(string $url): array;
    public function toHeader(): string;
    public function clear(): self;
}