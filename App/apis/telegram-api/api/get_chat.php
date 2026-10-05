<?php
require_once __DIR__ . '/../utils/api_utils.php';

function getChat($chat_id)
{
    $params = [
        'chat_id' => $chat_id
    ];
    $bot_reply = bot('getChat', $params);
    if ($bot_reply["ok"] == false) {
        Logger::warning('getChat failed', array('chat_id' => $chat_id, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('getChat ok', array('chat_id' => $chat_id));
        return $bot_reply;
    }
}

function getMe()
{
    $bot_reply = bot('getMe', []);
    if ($bot_reply["ok"] == false) {
        Logger::warning('getMe failed', array('error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('getMe ok', array());
        return $bot_reply;
    }
}
?>
