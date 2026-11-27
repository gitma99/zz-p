<?php
// Unit test: write_temp_file falls back to the file when the APC store fails.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/apcu_tools.php';
// temp_tools.php pulls in vars.php, which sets $TEMP_DIR_PATH.
require_once __DIR__ . '/../../App/Utils/temp_tools.php';
error_reporting(E_ALL ^ E_NOTICE);

// Point the temp dir at a unique sys temp dir so the test never touches
// the repo's real temp/. Must happen before any write_temp_file/read_temp_file.
$tmp = sys_get_temp_dir() . '/bt_' . getmypid();
@mkdir($tmp, 0770, true);
$TEMP_DIR_PATH = $tmp;

// Round-trip: json content written, read back, decoded to the original array.
$result = write_temp_file(['a' => 1], 't1.json', true);
assert_true($result !== false, 'write_temp_file succeeded');
$data = read_temp_file('t1.json', true);
assert_eq(['a' => 1], $data[0], 'read_temp_file round-trips json content');

// If the APC store is not live, the file backend must have been used.
if (!apcu_live()) {
    assert_true(file_exists($tmp . '/t1.json'), 'file fallback wrote t1.json when cache not live');
}

// Cleanup
delete_temp_file('t1.json');
@rmdir($tmp);

echo "OK temp_tools_test.php\n";
exit(0);
