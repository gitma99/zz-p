<?php
require_once __DIR__ . '/open_file.php';
require_once __DIR__ . '/write_file.php';
require_once __DIR__ . '/../vars.php';

function write_temp_file($content, $file_name, $json_encode) {
    global $TEMP_DIR_PATH;
    return write_file($content, $TEMP_DIR_PATH . "/{$file_name}", $json_decode);
}

function read_temp_file($file_name, $json_decode) {
    global $TEMP_DIR_PATH;
    return open_file($TEMP_DIR_PATH . "/{$file_name}", $json_decode);
}
?>