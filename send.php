<?php
require_once __DIR__ . "/App/vars.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/App/apis/telegram-api/api/send_message.php";
require_once __DIR__ . "/App/apis/telegram-api/api/forward_message.php";

ini_set('display_errors', 0); // Disable error display on the screen
ini_set('log_errors', 1); // Enable error logging to a file
if (!is_dir($LOG_DIR_PATH)) {
    mkdir($LOG_DIR_PATH, 0777, true); // Create directory with recursive permissions
}
ini_set('error_log', $LOG_DIR_PATH . '/error.log'); // Specify the path to the error log file
error_reporting(E_ALL); // Set the error reporting level as needed
date_default_timezone_set("UTC");

$users = $sql->query("SELECT * FROM `users`");
$sql_sends_result = $sql->query("SELECT * FROM `sends`");

$count = 0;
if ($sql_sends_result->num_rows !== 0) {
    $send_request = $sql_sends_result->fetch_assoc();
    if ($send_request['send'] == 'yes') {
        if ($send_request['step'] == 'send') {
            if ($send_request['type'] == 'text') {
                while ($row = $users->fetch_assoc()) {
                    sendMessage($row['from_id'], $send_request['text']);
                    $count++;
                }
                sendMessage($send_request['user'],"صف با موفقیت ارسال شد ✅");
                echo json_encode(['status' => true, 'msg' => 'send is successfuly.', 'status_code' => 200], 448);
            }
        } elseif ($user['step'] == 'forward') {
            while ($row = $users->fetch_assoc()) {
                forwardMessage($from_id, $row['from_id'], $send_request['text']);
                $count++;
            }
            echo json_encode(['status' => true, 'msg' => 'send is successfuly.', 'status_code' => 200], 448);
        } else {
            die('Error');
        }
    }
} else {
    echo json_encode(['status' => false, 'msg' => 'threr is no send request in db!', 'status_code' => 200], 448);
}

