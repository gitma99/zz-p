<?php
// Unit test: membership_key() — defined in App/Utils/pure.php (moved from config.php).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/pure.php';
error_reporting(E_ALL ^ E_NOTICE);

assert_eq('apcu:join:123:456', membership_key('join', 123, 456), 'join key');
assert_eq('apcu:joinSend:-100:789', membership_key('joinSend', -100, 789), 'joinSend key with negative chat');
assert_eq('apcu:foo:bar:baz', membership_key('foo', 'bar', 'baz'), 'string args');

assert_throws(function() {
    membership_key('', 123, 456);
}, InvalidArgumentException::class, 'membership_key throws on empty kind');

assert_throws(function() {
    membership_key('join', null, 456);
}, InvalidArgumentException::class, 'membership_key throws on null chat');

assert_throws(function() {
    membership_key('join', 123, null);
}, InvalidArgumentException::class, 'membership_key throws on null user');

echo "OK membership_test.php\n";
exit(0);
