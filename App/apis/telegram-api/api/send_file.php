<?php
require_once __DIR__ . '/../utils/api_utils.php';

function sendFile($chat_id, $file_path, $file_name, $mime_type, $api_key = API_KEY, $caption = null)
{
    $params = [
        'chat_id' => $chat_id,
        'document' => new CURLFile($file_path, $mime_type, $file_name)
    ];
    if ($caption !== null && $caption !== '') $params['caption'] = $caption;
    $bot_reply = bot('sendDocument', $params, $api_key);
    if ($bot_reply["ok"] == false) {
        Logger::warning('sendFile failed', array('chat_id' => $chat_id, 'file' => $file_name, 'error' => isset($bot_reply['description']) ? $bot_reply['description'] : json_encode($bot_reply)));
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        Logger::info('sendFile ok', array('chat_id' => $chat_id, 'file' => $file_name));
        return $bot_reply;
    }
}
?>