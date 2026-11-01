<?php

namespace Amirxd\UltraRequest\Auth;

class BearerAuth implements AuthInterface {
    private string $token;
    
    public function __construct(string $token) {
        if ($token === '' || !preg_match('/^[A-Za-z0-9\-._~+\/]+=*$/', $token)) {
            throw new \InvalidArgumentException('Invalid Bearer token.');
        }
        $this->token = $token;
    }
    
    public function getHeaders(): array {
        return [
            'Authorization' => 'Bearer ' . $this->token
        ];
    }
    
    public function getQueryParams(): array {
        return [];
    }
    
    public function getType(): string {
        return 'bearer';
    }
}