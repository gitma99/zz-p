<?php
function bot($method, $datas = [], $api_key = API_KEY, $only_handler = false)
{
    $handeled_exception_outside = [
        'sendDocument',
        'sendMessage',
        'forwardMessage',
        'editMessageText',
        'deleteMessage',
        'answerCallbackQuery',
    ];
    $url = "https://api.telegram.org/bot" . $api_key . "/" . $method;
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
        $res = curl_exec($ch);
        curl_close($ch);
        $curl_error = curl_error($ch);
        if ($res === false) {
            return $curl_error;
        } else {
            $telegram_res = json_decode($res, true);
            if ($telegram_res["ok"] == false and !in_array($method, $handeled_exception_outside)) {
                throw new Exception(json_encode($telegram_res, 448));
            } else {
                return $telegram_res;
            }
        }
    }
}
?>