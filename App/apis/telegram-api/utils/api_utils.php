<?php
function bot($method, $data = array(), $api_key = API_KEY, $only_handler = false, $retry_on_failure_count = 2)
{
    $curl_response = null;
    $curl_error = null;
    $telegram_res = null;

    $handeled_exception_outside = array(
        'sendDocument',
        'sendMessage',
        'forwardMessage',
        'editMessageText',
        'deleteMessage',
        'deleteMessages',
        'answerCallbackQuery',
        'sendPhoto',
        'editMessageReplyMarkup',
        'getChatMember',
        'getChat',
        'getMe',
    );
    $allowed = array();
    foreach ($handeled_exception_outside as $m) $allowed[] = strtolower($m);
    if (!in_array(strtolower($method), $allowed)) {
        throw new Exception("telegram-api.utils.bot is not configured to run '{$method}'", 1);
    }

    $url = "https://api.telegram.org/bot" . $api_key . "/" . $method;
    for ($i = 0; $i <= $retry_on_failure_count; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15
        ));
        if ($only_handler == true) {
            return $ch;
        }
        $curl_response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        if ($curl_response !== false) {
            $telegram_res = json_decode($curl_response, true);
            return $telegram_res;
        }
    }

    throw new Exception(
        json_encode(
            array(
                "params" => array(
                    "method" => $method,
                    "data" => $data
                ),
                "curl_response" => $curl_response,
                "curl_error" => $curl_error,
                "telegram_response" => $telegram_res
            ),
            448
        ),
        1
    );
}
?>
