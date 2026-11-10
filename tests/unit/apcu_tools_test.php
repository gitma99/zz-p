<?php
// Unit test: apcu_safe_* fail-fast contract assertions.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/apcu_tools.php';
error_reporting(E_ALL ^ E_NOTICE);

// Valid operations (fallback mode without ext-apcu)
assert_eq(false, apcu_safe_get('any:key'), 'apcu_safe_get fallback returns default false');
assert_eq('def', apcu_safe_get('any:key', 'def'), 'apcu_safe_get fallback returns custom default');
assert_eq(false, apcu_safe_set('any:key', 'val'), 'apcu_safe_set fallback returns false');
assert_eq(false, apcu_safe_del('any:key'), 'apcu_safe_del fallback returns false');

// Fail fast on empty or non-string key
assert_throws(function() {
    apcu_safe_get('');
}, InvalidArgumentException::class, 'apcu_safe_get throws on empty key');

assert_throws(function() {
    apcu_safe_get(null);
}, InvalidArgumentException::class, 'apcu_safe_get throws on null key');

assert_throws(function() {
    apcu_safe_set('', 'val');
}, InvalidArgumentException::class, 'apcu_safe_set throws on empty key');

assert_throws(function() {
    apcu_safe_del('');
}, InvalidArgumentException::class, 'apcu_safe_del throws on empty key');

// apcu_safe_add: tri-state contract.
// In the plain CLI image ext-apcu is absent => null (fail-open, no fatal).
if (function_exists('apcu_add')) {
    apcu_clear_cache();
    assert_eq(true, apcu_safe_add('add:key', 1, 120), 'apcu_safe_add adds a fresh key');
    assert_eq(false, apcu_safe_add('add:key', 2, 120), 'apcu_safe_add reports an existing key');
} else {
    assert_eq(null, apcu_safe_add('add:key', 1, 120), 'apcu_safe_add returns null without the extension');
    assert_eq(null, apcu_safe_add('add:key', 1, 120), 'repeated miss stays null, no fatal');
}
assert_throws(function() {
    apcu_safe_add('', 1);
}, InvalidArgumentException::class, 'apcu_safe_add throws on empty key');
assert_throws(function() {
    apcu_safe_add(null, 1);
}, InvalidArgumentException::class, 'apcu_safe_add throws on null key');

echo "OK apcu_tools_test.php\n";
exit(0);
