<?php
function sendFile($chat_id, $file_path, $file_name, $mime_type, $api_key = API_KEY)
{
    $params = [
        'chat_id' => $chat_id,
        'document' => new CURLFile($file_path, $mime_type, $file_name)
    ];
    $bot_reply = bot('sendDocument', $params, $api_key);
    if ($bot_reply["ok"] == false) {
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        return $bot_reply;
    }
}
?>