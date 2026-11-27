<?php
// Unit test: open_file() reports invalid JSON instead of returning [null, false],
// so callers (get_config, debug.php) can fail loudly on a corrupt config file.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/open_file.php';

$tmp = sys_get_temp_dir() . '/of_' . getmypid();
@mkdir($tmp, 0770, true);

// 1. Valid JSON decodes, no error reported.
$good = $tmp . '/good.json';
file_put_contents($good, '{"maintainer":"123"}');
$res = open_file($good, true);
assert_eq(['maintainer' => '123'], $res[0], 'valid JSON decodes to an array');
assert_eq(false, $res[1], 'valid JSON reports no error');

// 2. Invalid JSON: first element is false (not null), second is the error text.
$bad = $tmp . '/bad.json';
file_put_contents($bad, '{"maintainer":');
$res = open_file($bad, true);
assert_eq(false, $res[0], 'invalid JSON returns false, not a decoded value');
assert_true(is_string($res[1]) && strpos($res[1], 'Invalid JSON') !== false, 'invalid JSON returns an error message');

// 3. Raw (non-JSON) mode is untouched.
$res = open_file($bad, false);
assert_eq('{"maintainer":', $res[0], 'raw mode returns file content untouched');
assert_eq(false, $res[1], 'raw mode reports no error');

// 4. Missing file is an error value, not a fatal.
$res = @open_file($tmp . '/nope.json', true);
assert_eq(false, $res[0], 'missing file returns false');
assert_true(is_string($res[1]), 'missing file returns an error message');

@unlink($good);
@unlink($bad);
@rmdir($tmp);

echo "OK open_file_test.php\n";
exit(0);
