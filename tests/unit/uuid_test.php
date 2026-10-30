<?php
// Unit test: generateUUID() — defined in App/Utils/pure.php (mirrored from config.php).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/pure.php';
error_reporting(E_ALL ^ E_NOTICE);

$uuid = generateUUID();
assert_true(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid), 'UUID matches RFC4122 v4 shape');
assert_eq(36, strlen($uuid), 'UUID length is 36');
$uuid2 = generateUUID();
assert_true($uuid !== $uuid2, 'two calls differ');

echo "OK uuid_test.php\n";
exit(0);
