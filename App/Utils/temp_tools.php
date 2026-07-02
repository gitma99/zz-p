<?php
require_once __DIR__ . '/open_file.php';
require_once __DIR__ . '/write_file.php';
require_once __DIR__ . '/../vars.php';

function write_temp_file($content, $file_name, $json_encode = false) {
    if (function_exists('apcu_store')) {
        return apcu_store('botpanel_temp_' . $file_name, $content, 86400);
    }
    global $TEMP_DIR_PATH;
    return write_file($content, $TEMP_DIR_PATH . "/{$file_name}", $json_encode);
}

function read_temp_file($file_name, $json_decode = false) {
    if (function_exists('apcu_fetch')) {
        $data = apcu_fetch('botpanel_temp_' . $file_name, $success);
        if ($success) {
            return [$data, false];
        }
    }
    global $TEMP_DIR_PATH;
    return open_file($TEMP_DIR_PATH . "/{$file_name}", $json_decode);
}

function delete_temp_file($file_name) {
    if (function_exists('apcu_delete')) {
        apcu_delete('botpanel_temp_' . $file_name);
    }
    global $TEMP_DIR_PATH;
    $file_path = $TEMP_DIR_PATH . "/{$file_name}";
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}
?>