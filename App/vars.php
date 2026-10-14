<?php

$APP_VERSION = "1.5.1";
$LOG_DIR_PATH = realpath(__DIR__ . '/../logs') ?: (__DIR__ . '/../logs');
$BOT_CONFIG_PATH = realpath(__DIR__ . '/../bot_config.json') ?: (__DIR__ . '/../bot_config.json');
$TEMP_DIR_PATH = realpath(__DIR__ . '/../temp') ?: (__DIR__ . '/../temp');
if (!is_dir($TEMP_DIR_PATH)) @mkdir($TEMP_DIR_PATH, 0770, true);
if (!is_dir($LOG_DIR_PATH)) @mkdir($LOG_DIR_PATH, 0770, true);

require_once __DIR__ . '/Utils/Logger.php';

?>