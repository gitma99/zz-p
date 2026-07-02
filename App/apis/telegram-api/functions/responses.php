<?php
function sendSuccessResponse($from_id, $qr_code_status, $qr_data, $caption_text, $message_text, $start_key, $retry_count = 3)
{
    if ($qr_code_status == 'active') {
        $encode_url = urlencode($qr_data);
        $photo_url = "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800";
        $last_exception = null;
        for ($i = 0; $i < $retry_count; $i++) {
            try {
                $bot_reply = bot('sendPhoto', [
                    'chat_id' => $from_id,
                    'photo' => $photo_url,
                    'caption' => $caption_text,
                    'parse_mode' => 'html',
                    'reply_markup' => $start_key
                ]);
                if (isset($bot_reply['ok']) && $bot_reply['ok'] === true) {
                    return $bot_reply;
                }
                throw new Exception(json_encode($bot_reply));
            } catch (Exception $e) {
                $last_exception = $e;
            }
        }
        // Fallback: send without QR code
        try {
            sendMessage($from_id, $message_text, $start_key);
        } catch (Exception $send_msg_ex) {
            // Suppress fallback exception to preserve the original photo send failure details
        }
        throw $last_exception;
    } else {
        return sendMessage($from_id, $message_text, $start_key);
    }
}

?>