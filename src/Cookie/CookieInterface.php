<?php

namespace Amirxd\UltraRequest\Cookie;

interface CookieInterface {
    public function getName(): string;
    public function getValue(): string;
    public function getDomain(): ?string;
    public function getPath(): string;
    public function isExpired(): bool;
    public function isSecure(): bool;
    public function isHttpOnly(): bool;
    public function matches(string $url): bool;
    public function __toString(): string;
    public function __serialize(): array;
}