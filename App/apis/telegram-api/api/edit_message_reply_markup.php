<?php
require_once __DIR__ . '/../utils/api_utils.php';

function editMessageReplyMarkup($chat_id, $message_id, $keyboard)
{
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'reply_markup' => $keyboard
    ];
    $bot_reply = bot('editMessageReplyMarkup', $params);
    if ($bot_reply["ok"] == false) {
        Logger::warning('editMessageReplyMarkup failed', array('chat_id' => $chat_id, 'message_id' => $message_id, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('editMessageReplyMarkup ok', array('chat_id' => $chat_id, 'message_id' => $message_id));
        return $bot_reply;
    }
}
?>
