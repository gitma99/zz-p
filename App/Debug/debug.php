<?php
require_once __DIR__ . "/../Utils/write_file.php";
require_once __DIR__ . "/../Utils/open_file.php";
require_once __DIR__ . "/../Utils/config_tools.php";
require_once __DIR__ . '/../vars.php';

function send_debug_data_to_maintainer($text_string, $dev_id = null, $exit = false) {
    global $LOG_DIR_PATH;
    if (is_null($dev_id)){
        $config = get_config();
        if ($config[1] !== false){
            throw new Exception(json_encode($config, 448));
        }
        $dev_id = $config[0]['maintainer']; 
    }
    $current_time = time();
    $file_name = "debug-{$current_time}.txt";
    $file_path = $LOG_DIR_PATH . "/$file_name";
    write_file($text_string, $file_path);
    sendFile($dev_id, $file_path, $file_name, 'text/plain');
    @unlink($file_path);
    if ($exit) {
        exit(0);
    }
}

function send_debug_msg_to_maintainer($text, $dev_id, $exit = false)
{
    if (is_null($dev_id)){
        $config = get_config();
        if ($config[1] !== false){
            throw new Exception(json_encode($config, 448));
        }
        $dev_id = $config[0]['maintainer']; 
    }
    $bot_response = sendMessage($dev_id, $text);
    if ($bot_response["ok"] == false) {
        return send_debug_data_to_maintainer($text, $dev_id, $exit);
    }
    if ($exit) {
        exit(0);
    }
}
?>