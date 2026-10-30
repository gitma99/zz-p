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

echo "OK apcu_tools_test.php\n";
exit(0);
