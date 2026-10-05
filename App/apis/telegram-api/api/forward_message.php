<?php
require_once __DIR__ . '/../utils/api_utils.php';

function forwardMessage($from, $to, $message_id, $mrk = 'html')
{
    $params = [
        'chat_id' => $to,
        'from_chat_id' => $from,
        'message_id' => $message_id,
        'parse_mode' => $mrk
    ];
    $bot_reply = bot('forwardMessage', $params);
    if ($bot_reply["ok"] == false) {
        Logger::warning('forwardMessage failed', array('chat_id' => $to, 'message_id' => $message_id, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('forwardMessage ok', array('chat_id' => $to, 'message_id' => $message_id));
        return $bot_reply;
    }
}
?>