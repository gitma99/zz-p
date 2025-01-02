<?php
require_once __DIR__ . "/../Utils/write_file.php";
require_once __DIR__ . "/../Utils/open_file.php";
require_once __DIR__ . "/../Utils/config_tools.php";
require_once __DIR__ . '/../vars.php';

function send_debug_data_to_maintainer($text_string, $dev_id = null, $exit = false) {
    if (is_null($dev_id)){
        $dev_id = get_config()[0]['maintainer'];
    }
    $file_name = `debug_` . time() . `.txt`;
    $file_path = realpath($LOG_DIR_PATH . $file_name);
    write_file($file_path, $text_string);
    sendFile($dev_id, $file_path, $file_name, 'text/plain');
    unlink($file_path);
    if ($exit) {
        exit(0);
    }
}

function send_debug_msg_to_maintainer($text, $dev_id, $exit = false)
{
    if (is_null($dev_id)){
        $dev_id = get_config()[0]['maintainer'];
    }
    $bot_response = sendMessage($dev_id, $text);
    if ($bot_response["ok"] == false) {
        return send_debug_data_to_maintainer($text, $exit);
    }
    if ($exit) {
        exit(0);
    }
}

function send_multiple_debug_msg_to_maintainer($dev_id, $exit = false, ...$content_array)
{
    if (is_null($dev_id)){
        $dev_id = get_config()[0]['maintainer'];
    }
    $bot_response = sendMessage($dev_id, json_encode($content_array, 448));
    if ($bot_response["ok"] == false) {
        return send_debug_data_to_maintainer(json_encode($content_array, 448), $exit);
    }
    if ($exit) {
        exit(0);
    }
}
?>