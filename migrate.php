<?php
date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL ^ E_NOTICE);
$CONFIG_JSON_PATH = "Config/bot_config.json";

$bot_config = json_decode(file_get_contents($CONFIG_JSON_PATH), true);
// ==================================== migration works
if (!isset($bot_config["maintainer"])){
    $bot_config["maintainer"] = $bot_config["dev"];
}
// ==================================== END
$bot_config_data_json = json_encode($bot_config, JSON_PRETTY_PRINT);
if (file_put_contents($CONFIG_JSON_PATH, $bot_config_data_json)){
    echo "INFO: Successfully migrated the new configs!";
} else {
    echo "ERROR: Failed to write to config file!";
}
?>