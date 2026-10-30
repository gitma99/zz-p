<?php
function sendSuccessResponse($from_id, $qr_code_status, $qr_data, $caption_text, $message_text, $start_key, $retry_count = 3, $sleeper = 'sleep')
{
    global $texts;
    if ($qr_code_status == 'active') {
        $encode_url = urlencode($qr_data);
        $photo_url = "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800";
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
                if (isset($bot_reply['error_code']) && $bot_reply['error_code'] === 429) {
                    $retry_after = (isset($bot_reply['parameters']['retry_after']) && is_numeric($bot_reply['parameters']['retry_after']) && (int)$bot_reply['parameters']['retry_after'] > 0)
                        ? (int)$bot_reply['parameters']['retry_after']
                        : 3;
                    if ($retry_after > 5) {
                        Logger::warning('sendPhoto 429 retry_after exceeds threshold, aborting retries', array('chat_id' => $from_id, 'retry_after' => $retry_after));
                        break;
                    }
                    Logger::warning('sendPhoto 429 rate limited, waiting to retry', array('chat_id' => $from_id, 'attempt' => $i + 1, 'retry_after' => $retry_after));
                    if ($i + 1 < $retry_count && is_callable($sleeper)) {
                        call_user_func($sleeper, $retry_after);
                    }
                    continue;
                }
                throw new Exception(json_encode($bot_reply));
            } catch (Exception $e) {
                Logger::warning('sendPhoto retry failed', array('chat_id' => $from_id, 'attempt' => $i + 1, 'error' => $e->getMessage()));
            }
        }
        Logger::error('sendPhoto retries exhausted, falling back to text', array('chat_id' => $from_id, 'attempts' => $retry_count));
        // Fallback: no QR photo possible (e.g. Telegram cannot fetch it).
        // Never dump the raw link; point to the QR in My Services instead.
        $fallback_text = isset($texts['qr_unavailable_hint']) ? $caption_text . "\n\n" . $texts['qr_unavailable_hint'] : $caption_text;
        try {
            return sendMessage($from_id, $fallback_text, $start_key);
        } catch (Exception $send_msg_ex) {
            Logger::error('sendSuccessResponse fallback failed', array('chat_id' => $from_id, 'error' => $send_msg_ex->getMessage()));
        }
        return null;
    } else {
        return sendMessage($from_id, $message_text, $start_key);
    }
}

?>