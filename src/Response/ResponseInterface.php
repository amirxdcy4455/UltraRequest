<?php

namespace Amirxd\UltraRequest\Response;

interface ResponseInterface {
    public function getBody(): ?string;
    public function getHeaders(): array;
    public function getHeader(string $name): ?string;
    public function getStatusCode(): int;
    public function isSuccessful(): bool;
    public function isRedirect(): bool;
    public function isClientError(): bool;
    public function isServerError(): bool;
    public function getJson(bool $assoc = true);
    public function getInfo(): array;
}