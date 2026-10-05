<?php
require_once __DIR__ . '/../utils/api_utils.php';

function sendMessage($chat_id, $text, $keyboard = null, $mrk = 'html')
// function sendMessage($chat_id, $text, $keyboard = null, $mrk = 'html', $api_key = API_KEY)
{
    if (empty($text)) {
        Logger::warning('sendMessage skipped: empty text', array('chat_id' => $chat_id));
        return false;
    }
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $mrk,
        'disable_web_page_preview' => true,
        'reply_markup' => $keyboard
    ];
    $bot_reply = bot('sendMessage', $params);
    // $bot_reply =  bot('sendMessage', $params, $api_key);
    if ($bot_reply["ok"] === false) {
        Logger::warning('sendMessage failed', array('chat_id' => $chat_id, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        // if (isset($bot_reply["description"]) && !empty($bot_reply["description"])){
        //     if (strpos($bot_reply["description"], "bot was blocked by the user") !== false) {
        //         return False;
        //     }
        // }
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('sendMessage ok', array('chat_id' => $chat_id));
        return $bot_reply;
    }
}

?>