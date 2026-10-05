<?php
require_once __DIR__ . '/../utils/api_utils.php';

function getChatMember($chat_id, $user_id)
{
    $params = [
        'chat_id' => $chat_id,
        'user_id' => $user_id
    ];
    $bot_reply = bot('getChatMember', $params);
    if ($bot_reply["ok"] == false) {
        Logger::warning('getChatMember failed', array('chat_id' => $chat_id, 'user_id' => $user_id, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('getChatMember ok', array('chat_id' => $chat_id, 'user_id' => $user_id));
        return $bot_reply;
    }
}
?>
