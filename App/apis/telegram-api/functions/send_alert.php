<?php
require_once __DIR__ . '/../utils/api_utils.php';

function alert($text, $show = true)
{
    global $query_id;
    $params = [
        'callback_query_id' => $query_id,
        'text' => $text,
        'show_alert' => $show
    ];
    $bot_reply = bot('answerCallbackQuery', $params);
    if ($bot_reply["ok"] == false) {
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        return $bot_reply;
    }
}
?>