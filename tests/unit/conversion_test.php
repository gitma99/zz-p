<?php
// Unit test: Conversion() — defined in App/Utils/pure.php (mirrored from config.php).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/pure.php';
error_reporting(E_ALL ^ E_NOTICE);

assert_eq(1.0, Conversion(1073741824, 'GB'), '1 GB -> 1.0');
assert_eq(0.5, Conversion(536870912, 'GB'), '0.5 GB -> 0.5');
assert_eq(1024.0, Conversion(1073741824, 'MB'), '1 GB -> 1024.0 MB');
assert_eq(1.0, Conversion(1048576, 'MB'), '1 MB -> 1.0');
assert_eq(1024.0, Conversion(1048576, 'KB'), '1 MB -> 1024.0 KB');
assert_eq(1.0, Conversion(1024, 'KB'), '1 KB -> 1.0');
assert_eq(1.0, Conversion(1073741824), 'default unit is GB -> 1.0');
assert_eq(null, Conversion(100, 'ZZ'), 'unknown unit -> null');

echo "OK conversion_test.php\n";
exit(0);
