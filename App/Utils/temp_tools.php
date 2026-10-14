<?php
// FlowState vocabulary (see GLOSSARY.md): delete on flow end/cancel; TTL is backstop only.
require_once __DIR__ . '/open_file.php';
require_once __DIR__ . '/write_file.php';
require_once __DIR__ . '/../vars.php';

define('FLOW_TTL', 86400);

function temp_key($file_name) {
    return 'botpanel_temp_' . basename($file_name);
}

function write_temp_file($content, $file_name, $json_encode = false) {
    if ($json_encode) $content = json_encode($content);
    if (function_exists('apcu_store')) {
        return apcu_store(temp_key($file_name), $content, FLOW_TTL);
    }
    global $TEMP_DIR_PATH;
    return write_file($content, $TEMP_DIR_PATH . "/" . basename($file_name));
}

function read_temp_file($file_name, $json_decode = false) {
    if (function_exists('apcu_fetch')) {
        $data = apcu_fetch(temp_key($file_name), $success);
        if ($success) {
            if ($json_decode && is_string($data)) $data = json_decode($data, true);
            return [$data, false];
        }
    }
    global $TEMP_DIR_PATH;
    return open_file($TEMP_DIR_PATH . "/" . basename($file_name), $json_decode);
}

function delete_temp_file($file_name) {
    if (function_exists('apcu_delete')) {
        apcu_delete(temp_key($file_name));
    }
    global $TEMP_DIR_PATH;
    $file_path = $TEMP_DIR_PATH . "/" . basename($file_name);
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}
?>