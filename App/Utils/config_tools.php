<?php
require_once __DIR__ . "/write_file.php";
require_once __DIR__ . "/open_file.php";
require_once __DIR__ . '/../vars.php';

function get_config() {
    return open_file($BOT_CONFIG_PATH, TRUE);
}

function write_config($config){
    if (isset($config)){
        throw new Exception("App.Ultils.config_file.write_config: 'config' is not set.", 1);
    }
    return write_file($config, $BOT_CONFIG_PATH, True);
}
?>