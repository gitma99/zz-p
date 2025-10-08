<?php
function bot($method, $datas = [], $api_key = API_KEY, $only_handler = false, retry_on_failure_count = 2)
{
    $curl_response = null;
    $curl_error = null;
    $telegram_res = null;

    $handeled_exception_outside = [
        'sendDocument',
        'sendMessage',
        'forwardMessage',
        'editMessageText',
        'deleteMessage',
        'answerCallbackQuery',
    ];
    if (!in_array($method, $handeled_exception_outside)){
        throw new Exception("telegram-api.utils.bot is not configured to run '${method}'", 1);
    }

    $url = "https://api.telegram.org/bot" . $api_key . "/" . $method;
    for ($i=0; $i <= retry_on_failure_count ; $i++) { 
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POSTFIELDS => $datas
        ]);
        if ($only_handler == true) {
            return $ch;
        } else {
            $curl_response = curl_exec($ch);
            curl_close($ch);
            $curl_error = curl_error($ch);
            if ($curl_response !== false) {
                $telegram_res = json_decode($curl_response, true);
                if ($telegram_res["ok"] === true) {
                    return $telegram_res;
                }
            }
        }
    }

    throw new Exception(
        json_encode(
            [
                "params" => [
                    "method" => $method,
                    "data" => $data
                ],
                "curl_response" => $curl_response,
                "curl_error" => $curl_error,
                "telegram_response" => $telegram_res
            ], 
            448
        ),
        1
    );
}

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
        throw new Exception(json_encode($bot_reply, 448));
    } else {
        return $bot_reply;
    }
}

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