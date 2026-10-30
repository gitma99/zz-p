<?php
// Unit test: convertToBytes() — defined in App/Utils/pure.php (mirrored from config.php).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/pure.php';
error_reporting(E_ALL ^ E_NOTICE);

assert_eq(1073741824, convertToBytes('1GB'), '1 GB');
assert_eq(5368709120, convertToBytes('5GB'), '5 GB');
assert_eq(1073741824, convertToBytes('1024MB'), '1024 MB = 1 GB');
assert_eq(1048576, convertToBytes('1024KB'), '1024 KB = 1 MB');
assert_eq('1', convertToBytes('1B'), '1 B -> string "1" (digit-suffix branch)');
assert_eq(1125899906842624, convertToBytes('1PB'), '1 PB');
assert_eq(null, convertToBytes('abc'), 'bad suffix -> null');
assert_eq('10', convertToBytes('10X'), 'suffix starts with a digit -> strips non-digits');

echo "OK converttobytes_test.php\n";
exit(0);
