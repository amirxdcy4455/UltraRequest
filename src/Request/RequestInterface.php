<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Request;

interface RequestInterface
{
    public function getMethod(): string;
    public function getUrl(): string;
    public function getHeaders(): array;
    public function getBody();
    public function followsRedirects(): bool;
    public function getMaxRedirects(): ?int;
    public function getUrlMethod(): ?array;
}
