<?php
require_once __DIR__ . "/write_file.php";
require_once __DIR__ . "/open_file.php";
require_once __DIR__ . '/../vars.php';

function get_config() {
    global $BOT_CONFIG_PATH;
    return open_file($BOT_CONFIG_PATH, TRUE);
}

function write_config($config){
    global $BOT_CONFIG_PATH;
    if (!isset($config)){
        throw new Exception("App.Ultils.config_file.write_config: 'config' is not set.", 1);
    }
    $res = write_file($config, $BOT_CONFIG_PATH, True);
    if (function_exists('apcu_delete')) apcu_delete('botpanel_config');
    return $res;
}