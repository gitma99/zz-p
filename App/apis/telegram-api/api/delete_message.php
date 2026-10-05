<?php
require_once __DIR__ . '/../utils/api_utils.php';

function deleteMessage($chat_id, $message_id, $only_handler = false)
{
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ];
    $bot_reply = bot('deleteMessage', $params);
    if ($bot_reply["ok"] == false) {
        Logger::warning('deleteMessage failed', array('chat_id' => $chat_id, 'message_id' => $message_id, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('deleteMessage ok', array('chat_id' => $chat_id, 'message_id' => $message_id));
        return $bot_reply;
    }
}
function deleteMessages($chat_id, $message_ids, $only_handler = false)
{
    $params = [
        'chat_id' => $chat_id,
        'message_ids' => json_encode(array_values((array)$message_ids))
    ];
    $bot_reply = bot('deleteMessages', $params);
    if ($bot_reply["ok"] == false) {
        Logger::warning('deleteMessages failed', array('chat_id' => $chat_id, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('deleteMessages ok', array('chat_id' => $chat_id));
        return $bot_reply;
    }
}
?>