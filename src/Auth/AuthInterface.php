<?php

namespace Amirxd\UltraRequest\Auth;

interface AuthInterface{
    public function getHeaders(): array;
    public function getQueryParams(): array;
    public function getType(): string;
}