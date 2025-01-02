<?php
function sendMessage($chat_id, $text, $keyboard = null, $mrk = 'html')
// function sendMessage($chat_id, $text, $keyboard = null, $mrk = 'html', $api_key = API_KEY)
{
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $mrk,
        'disable_web_page_preview' => true,
        'reply_markup' => $keyboard
    ];
    $bot_reply = bot('sendMessage', $params);
    // $bot_reply =  bot('sendMessage', $params, $api_key);
    if ($bot_reply["ok"] == false) {
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        return $bot_reply;
    }
}
?>