<?php

namespace Amirxd\UltraRequest\Auth;

class AuthManager {
    private ?AuthInterface $auth = null;
    
    public function __construct(?AuthInterface $auth = null) {
        $this->auth = $auth;
    }
    
    public function basic(string $username, string $password): self {
        $this->auth = new BasicAuth($username, $password);
        return $this;
    }
    
    public function bearer(string $token): self {
        $this->auth = new BearerAuth($token);
        return $this;
    }
    
    public function apiKey(string $key, string $value, string $placement = 'header', ?string $prefix = null): self {
        $this->auth = new ApiKeyAuth($key, $value, $placement, $prefix);
        return $this;
    }
    
    public function getAuth(): ?AuthInterface {
        return $this->auth;
    }
    
    public function getHeaders(): array {
        return $this->auth ? $this->auth->getHeaders() : [];
    }
    
    public function getQueryParams(): array {
        return $this->auth ? $this->auth->getQueryParams() : [];
    }
}