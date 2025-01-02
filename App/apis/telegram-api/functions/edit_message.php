<?php
require_once __DIR__ . '/../utils/api_utils.php';

function editMessage($chat_id, $text, $message_id, $keyboard = null, $mrk = 'html')
{
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => '⏳',
    ];
    bot('editMessageText', $params);

    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => $mrk,
        'disable_web_page_preview' => true,
        'reply_markup' => $keyboard
    ];
    $bot_reply = bot('editMessageText', $params);
    if ($bot_reply["ok"] == false) {
        if ($bot_reply["description"] == "Bad Request: inline keyboard expected") {
            deleteMessage($chat_id, $message_id);
            $bot_reply = sendMessage($chat_id, $text, $keyboard, $mrk = 'html');
            if ($bot_reply["ok"] == false) {
                throw new Exception(json_encode($bot_reply, 448));
            }
        } else {
            throw new Exception(json_encode($bot_reply, 448));
        }
    } else {
        return $bot_reply;
    }
}
?>