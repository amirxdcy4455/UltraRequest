<?php

namespace Amirxd\UltraRequest\Proxy;

interface ProxyInterface {
    public function withAuth(string $username, string $password): self;
    public function getCurlOptions(): array;
    public function getType(): string;
    public function getHost(): string;
    public function getPort(): int;
    public function __toString(): string;
}