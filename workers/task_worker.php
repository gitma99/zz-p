<?php
if (php_sapi_name() !== 'cli') {
    die("Only CLI mode supported.");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../App/apis/marzban-api/api.php';
require_once __DIR__ . '/../App/functions/service_overview.php';

function log_message($msg) {
    $date = date('Y-m-d H:i:s');
    Logger::info($msg);
    echo "[$date] $msg\n";
}

log_message("Worker started.");

while (true) {
    // 1. Recover stale tasks (locked for more than 5 minutes)
    $sql->query("UPDATE `task_queue` SET `status` = 'pending', `started_at` = NULL, `locked_at` = NULL WHERE `status` = 'running' AND `locked_at` < NOW() - INTERVAL 5 MINUTE");
    
    // 2. Claim one pending task (row lock held via transaction until COMMIT)
    $sql->begin_transaction();
    $claim = $sql->query("SELECT `id` FROM `task_queue` WHERE `status` = 'pending' ORDER BY `id` ASC LIMIT 1 FOR UPDATE SKIP LOCKED");

    if (!$claim || $claim->num_rows === 0) {
        $sql->rollback();
        sleep(3);
        continue;
    }

    $task_id = (int)$claim->fetch_assoc()['id'];
    $sql->query("UPDATE `task_queue` SET `status` = 'running', `started_at` = NOW(), `locked_at` = NOW() WHERE `id` = $task_id");
    $sql->commit();

    // 3. Fetch the claimed task
    $task_res = $sql->query("SELECT * FROM `task_queue` WHERE `id` = $task_id");
    if (!$task_res || $task_res->num_rows === 0) {
        sleep(3);
        continue;
    }

    $task = $task_res->fetch_assoc();
    $payload = json_decode($task['payload'], true);
    $user_id = $payload['user_id'] ?? null;
    $chat_id = $payload['chat_id'] ?? null;
    $message_id = $payload['message_id'] ?? null;
    
    log_message("Processing task ID $task_id of type '{$task['type']}' for user $user_id...");
    
    try {
        if ($task['type'] === 'service_overview') {
            $overview = fetch_user_service_overview($user_id, $sql);
            
            if ($overview['error']) {
                throw new Exception($overview['error']);
            }
            
            // Format result
            if ($overview['empty']) {
                $result_text = $texts['services_overview__alert__msg_no_service'];
            } else {
                $result_text = sprintf(
                    $texts['services_overview__alert__msg'],
                    $overview['count_all_active'],
                    $overview['count_all_inactive'],
                    $overview['overall_unknown'],
                    Conversion($overview['total_traffic_bought'], "GB"),
                    Conversion($overview['total_traffic_used'], "GB")
                );
            }
            
            // Save to task_results
            $stmt = $sql->prepare("INSERT INTO `task_results` (`task_id`, `result`, `created_at`) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE `result` = VALUES(`result`), `created_at` = NOW()");
            $stmt->bind_param("is", $task_id, $result_text);
            $stmt->execute();
            $stmt->close();
            
            // Edit Telegram Message inline button
            $new_keyboard = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => $texts['button__show_results'], 'callback_data' => 'show_task_result__' . $task_id]
                    ]
                ]
            ]);
            
            editMessageReplyMarkup($chat_id, $message_id, $new_keyboard);
            
            // Mark task as done
            $sql->query("UPDATE `task_queue` SET `status` = 'done' WHERE `id` = $task_id");
            log_message("Task ID $task_id completed successfully.");
        } else {
            throw new Exception("Unknown task type: " . $task['type']);
        }
    } catch (Exception $e) {
        log_message("Error processing task ID $task_id: " . $e->getMessage());
        $sql->query("UPDATE `task_queue` SET `status` = 'failed' WHERE `id` = $task_id");
        
        // Edit Telegram message to error state
        $err_keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $texts['button__error_processing'], 'callback_data' => 'pending_task']
                ]
            ]
        ]);
        editMessageReplyMarkup($chat_id, $message_id, $err_keyboard);
    }
}
