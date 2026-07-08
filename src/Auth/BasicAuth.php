<?php

namespace Amirxd\UltraRequest\Auth;

class BasicAuth implements AuthInterface {
    private string $username;
    private string $password;
    
    public function __construct(string $username, string $password) {
        $this->username = $username;
        $this->password = $password;
    }
    
    public function getHeaders(): array {
        $encoded = base64_encode($this->username . ':' . $this->password);
        return [
            'Authorization' => 'Basic ' . $encoded
        ];
    }
    
    public function getQueryParams(): array {
        return [];
    }
    
    public function getType(): string {
        return 'basic';
    }
}