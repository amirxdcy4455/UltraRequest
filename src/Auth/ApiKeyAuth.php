<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Auth;

class ApiKeyAuth implements AuthInterface
{
    private string $key;
    private string $value;
    private string $placement;
    private ?string $prefix;

    public function __construct(string $key, string $value, string $placement = 'header', ?string $prefix = null)
    {
        if ($key === '' || preg_match('/[\r\n]/', $key) || preg_match('/[\r\n]/', $value) || ($prefix !== null && preg_match('/[\r\n]/', $prefix))) {
            throw new \InvalidArgumentException('Invalid API key name or value.');
        }
        $placement = strtolower($placement);
        if (!in_array($placement, ['header', 'query'], true)) {
            throw new \InvalidArgumentException('API key placement must be "header" or "query".');
        }
        if ($placement === 'header' && preg_match('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', $key)) {
            throw new \InvalidArgumentException('Invalid API key header name.');
        }
        $this->key = $key;
        $this->value = $value;
        $this->placement = $placement;
        $this->prefix = $prefix;
    }

    public function getHeaders(): array
    {
        if ($this->placement !== 'header') return [];
        return [$this->key => $this->prefix !== null && $this->prefix !== '' ? $this->prefix . ' ' . $this->value : $this->value];
    }

    public function getQueryParams(): array
    {
        return $this->placement === 'query' ? [$this->key => $this->value] : [];
    }

    public function getType(): string { return 'api_key'; }
}
