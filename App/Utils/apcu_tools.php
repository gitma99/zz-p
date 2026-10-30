<?php
// Fail-open APCu helpers. Safe to call with or without ext-apcu:
// miss or missing extension returns $default / false, never fatals.
// Named apcu_safe_* to avoid colliding with the extension's own functions.
function apcu_warn_once() {
    static $warned = false;
    if ($warned) return;
    $warned = true;
    if (class_exists('Logger')) Logger::warning('APCu unavailable; using fallback cache');
}

function apcu_safe_get($k, $default = false) {
    if (!is_string($k) || $k === '') {
        throw new InvalidArgumentException("apcu_safe_get: key must be a non-empty string");
    }
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch($k, $hit);
        if ($hit) return $v;
    }
    apcu_warn_once();
    return $default;
}

function apcu_safe_set($k, $v, $ttl = 0) {
    if (!is_string($k) || $k === '') {
        throw new InvalidArgumentException("apcu_safe_set: key must be a non-empty string");
    }
    if (function_exists('apcu_store')) {
        return apcu_store($k, $v, $ttl);
    }
    apcu_warn_once();
    return false;
}

function apcu_safe_del($k) {
    if (!is_string($k) || $k === '') {
        throw new InvalidArgumentException("apcu_safe_del: key must be a non-empty string");
    }
    if (function_exists('apcu_delete')) {
        return apcu_delete($k);
    }
    return false;
}
