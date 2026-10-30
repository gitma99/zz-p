<?php
// Unit test: convertPersianToEnglish() — defined in App/Utils/pure.php (moved from api/arz.php).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/pure.php';
error_reporting(E_ALL ^ E_NOTICE);

assert_eq('0123456789', convertPersianToEnglish('۰۱۲۳۴۵۶۷۸۹'), 'all Persian digits');
assert_eq('abc', convertPersianToEnglish('abc'), 'no digits unchanged');
assert_eq('a1b2', convertPersianToEnglish('a۱b۲'), 'mixed');
assert_eq('', convertPersianToEnglish(''), 'empty string');
assert_eq('1000', convertPersianToEnglish('۱۰۰۰'), 'Persian 1000');

echo "OK converpersian_test.php\n";
exit(0);
