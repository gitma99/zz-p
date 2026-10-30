<?php
// Unit test: bytes_to_int_gb() — defined in App/Utils/pure.php (moved from tools/restore_marzban_orders.php).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/pure.php';
error_reporting(E_ALL ^ E_NOTICE);

assert_eq(1, bytes_to_int_gb(1073741824), '1 GB exactly');
assert_eq(0, bytes_to_int_gb(1073741823), '1 byte less than 1 GB -> 0');
assert_eq(2, bytes_to_int_gb(2147483648), '2 GB');
assert_eq(0, bytes_to_int_gb(0), 'zero bytes');
assert_eq(0, bytes_to_int_gb('abc'), 'non-numeric -> 0');
assert_eq(10, bytes_to_int_gb(10737418240), '10 GB');

echo "OK bytestointgb_test.php\n";
exit(0);
