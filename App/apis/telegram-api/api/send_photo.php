<?php
require_once __DIR__ . '/../utils/api_utils.php';

function sendPhoto($chat_id, $photo, $caption = '', $keyboard = null, $parse_mode = 'html')
{
    $params = [
        'chat_id' => $chat_id,
        'photo' => $photo,
        'caption' => $caption,
        'parse_mode' => $parse_mode,
        'reply_markup' => $keyboard
    ];
    $bot_reply = bot('sendPhoto', $params);
    if ($bot_reply["ok"] == false) {
        Logger::warning('sendPhoto failed', array('chat_id' => $chat_id, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('sendPhoto ok', array('chat_id' => $chat_id));
        return $bot_reply;
    }
}
?>
