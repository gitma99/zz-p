<?php
// Unit test: apcu_safe_* fail-fast contract assertions.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/apcu_tools.php';
error_reporting(E_ALL ^ E_NOTICE);

// Whether the APCu cache is actually usable in this SAPI: the extension
// can be loaded yet inactive (CI runners, CLI with apc.enable_cli=0),
// where apcu_*() exist but are no-ops returning false.
$cache_live = function_exists('apcu_store') && apcu_store('apcu_tools_test_probe', 1, 1) === true;
if ($cache_live) {
    apcu_clear_cache();
}

// Valid operations: miss semantics are identical in all modes; set/del
// report true only when the cache is live.
assert_eq(false, apcu_safe_get('any:key'), 'apcu_safe_get miss returns default false');
assert_eq('def', apcu_safe_get('any:key', 'def'), 'apcu_safe_get miss returns custom default');
assert_eq($cache_live, apcu_safe_set('any:key', 'val'), 'apcu_safe_set reports cache availability');
assert_eq($cache_live, apcu_safe_del('any:key'), 'apcu_safe_del reports cache availability');

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
if ($cache_live) {
    assert_eq(true, apcu_safe_add('add:key', 1, 120), 'apcu_safe_add adds a fresh key');
    assert_eq(false, apcu_safe_add('add:key', 2, 120), 'apcu_safe_add reports an existing key');
} else {
    // null when ext-apcu absent; false when present but SAPI-inactive.
    $expected = function_exists('apcu_add') ? false : null;
    assert_eq($expected, apcu_safe_add('add:key', 1, 120), 'apcu_safe_add degrades when cache not live');
    assert_eq($expected, apcu_safe_add('add:key', 1, 120), 'repeated miss stays stable, no fatal');
}
assert_throws(function() {
    apcu_safe_add('', 1);
}, InvalidArgumentException::class, 'apcu_safe_add throws on empty key');
assert_throws(function() {
    apcu_safe_add(null, 1);
}, InvalidArgumentException::class, 'apcu_safe_add throws on null key');

echo "OK apcu_tools_test.php\n";
exit(0);
