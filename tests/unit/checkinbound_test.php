<?php
// Unit test: checkInbound() — defined in App/Utils/pure.php (mirrored from config.php).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/pure.php';
error_reporting(E_ALL ^ E_NOTICE);

assert_eq(true, checkInbound('{"vless":[{"tag":"VLESS"}]}', 'vless'), 'present, case-insensitive');
assert_eq(true, checkInbound('{"vless":[{"tag":"VLESS"}]}', 'VLESS'), 'exact tag match');
assert_eq(false, checkInbound('{"vless":[{"tag":"VLESS"}]}', 'vmess'), 'absent');
assert_eq(false, checkInbound('{}', 'vless'), 'empty object');
assert_eq(false, checkInbound('{"vless":[]}', 'vless'), 'empty protocol list');

assert_throws(function() {
    checkInbound('{"vless":[]}', '');
}, InvalidArgumentException::class, 'checkInbound throws on empty inbound');

assert_throws(function() {
    checkInbound([], 'vless');
}, InvalidArgumentException::class, 'checkInbound throws on non-string json');

echo "OK checkinbound_test.php\n";
exit(0);
