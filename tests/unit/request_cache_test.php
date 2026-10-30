<?php
// Unit test: cached_query(), RequestCache fail-fast contract assertions.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/request_cache.php';
error_reporting(E_ALL ^ E_NOTICE);

$cache->clear();

// Valid cached_query
$called = 0;
$val = cached_query('test:key', function() use (&$called) {
    $called++;
    return 'computed';
});
assert_eq('computed', $val, 'cached_query returns computed value');
assert_eq(1, $called, 'cached_query calls factory once');

$val2 = cached_query('test:key', function() use (&$called) {
    $called++;
    return 'computed2';
});
assert_eq('computed', $val2, 'cached_query returns cached value');
assert_eq(1, $called, 'cached_query does not call factory second time');

// Fail fast on empty or non-string key
assert_throws(function() {
    cached_query('', function() { return 1; });
}, InvalidArgumentException::class, 'cached_query throws on empty string key');

assert_throws(function() {
    cached_query(null, function() { return 1; });
}, InvalidArgumentException::class, 'cached_query throws on null key');

// Fail fast on non-callable second argument
assert_throws(function() {
    cached_query('valid:key', 'not_a_callable_function_xyz');
}, InvalidArgumentException::class, 'cached_query throws on non-callable factory');

// Fail fast on get_active_rows with invalid table name
assert_throws(function() {
    get_active_rows('key', 'bad table; DROP TABLE users;');
}, InvalidArgumentException::class, 'get_active_rows throws on invalid table name');

// Fail fast on get_panel_by_name with empty name
assert_throws(function() {
    get_panel_by_name('');
}, InvalidArgumentException::class, 'get_panel_by_name throws on empty panel name');

echo "OK request_cache_test.php\n";
exit(0);
