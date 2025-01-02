<?php
function deleteMessage($chat_id, $message_id, $only_handler = false)
{
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ];
    $bot_reply = bot('deleteMessage', $params);
    if ($bot_reply["ok"] == false) {
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        return $bot_reply;
    }
}
function deleteMessages($chat_id, $message_ids, $only_handler = false)
{
    $params = [
        'chat_id' => $chat_id,
        'message_ids' => json_encode($message_ids)
    ];
    $bot_reply = bot('deleteMessages', $params);
    if ($bot_reply["ok"] == false) {
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        return $bot_reply;
    }
}
?>