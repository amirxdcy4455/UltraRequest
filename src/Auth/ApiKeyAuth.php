<?php

namespace Amirxd\UltraRequest\Auth;

class ApiKeyAuth implements AuthInterface {
    private string $key;
    private string $value;
    private string $placement;
    private ?string $prefix;
    
    public function __construct(
        string $key, 
        string $value, 
        string $placement = 'header', 
        ?string $prefix = null
    ) {
        $this->key = $key;
        $this->value = $value;
        $this->placement = $placement;
        $this->prefix = $prefix;
    }
    
    public function getHeaders(): array {
        if ($this->placement !== 'header') {
            return [];
        }
        
        $value = $this->prefix ? $this->prefix . ' ' . $this->value : $this->value;
        return [$this->key => $value];
    }
    
    public function getQueryParams(): array {
        if ($this->placement !== 'query') {
            return [];
        }
        return [$this->key => $this->value];
    }
    
    public function getType(): string {
        return 'api_key';
    }
}