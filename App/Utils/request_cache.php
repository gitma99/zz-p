<?php
class RequestCache {
    private array $store = [];
    public function has(string $k): bool { return array_key_exists($k, $this->store); }
    public function get(string $k, $d = null) { return $this->store[$k] ?? $d; }
    public function set(string $k, $v): void {
        $this->store[$k] = $v;
    }
    public function toArray(): array { return $this->store; }
}

$cache = new RequestCache();