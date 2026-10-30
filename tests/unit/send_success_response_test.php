<?php
// Unit tests for sendSuccessResponse Telegram 429 rate limit backoff and fallback.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/Logger.php';

$bot_mock_queue = [];
$bot_calls = [];
function bot($method, $data = []) {
    global $bot_mock_queue, $bot_calls;
    $bot_calls[] = ['method' => $method, 'data' => $data];
    if (empty($bot_mock_queue)) {
        throw new Exception('No mock response queued for bot()');
    }
    $resp = array_shift($bot_mock_queue);
    if ($resp instanceof Exception) {
        throw $resp;
    }
    return $resp;
}

$send_message_calls = [];
function sendMessage($chat_id, $text, $keyboard = null) {
    global $send_message_calls;
    $send_message_calls[] = ['chat_id' => $chat_id, 'text' => $text, 'keyboard' => $keyboard];
    return ['ok' => true, 'result' => ['message_id' => 999]];
}

require_once __DIR__ . '/../../App/apis/telegram-api/functions/responses.php';

$slept = [];
$mock_sleeper = function ($seconds) use (&$slept) {
    $slept[] = $seconds;
};

function reset_test_state() {
    global $bot_mock_queue, $bot_calls, $send_message_calls, $slept;
    $bot_mock_queue = [];
    $bot_calls = [];
    $send_message_calls = [];
    $slept = [];
}

// 1. First attempt succeeds: sleeper not called
reset_test_state();
$bot_mock_queue[] = ['ok' => true, 'result' => ['message_id' => 1]];
$res = sendSuccessResponse(1001, 'active', 'testqr', 'Test Caption', 'Fallback Text', null, 3, $mock_sleeper);
assert_true(isset($res['ok']) && $res['ok'] === true, 'First attempt succeeds');
assert_eq(0, count($slept), 'Sleeper not called on success');
assert_eq(1, count($bot_calls), 'bot() called once');
assert_eq(0, count($send_message_calls), 'sendMessage() not called');

// 2. 429 with explicit retry_after = 2: sleeps 2s, succeeds on 2nd attempt
reset_test_state();
$bot_mock_queue[] = [
    'ok' => false,
    'error_code' => 429,
    'description' => 'Too Many Requests: retry after 2',
    'parameters' => ['retry_after' => 2]
];
$bot_mock_queue[] = ['ok' => true, 'result' => ['message_id' => 2]];
$res = sendSuccessResponse(1001, 'active', 'testqr', 'Test Caption', 'Fallback Text', null, 3, $mock_sleeper);
assert_true(isset($res['ok']) && $res['ok'] === true, 'Succeeds on 2nd attempt after 429');
assert_eq([2], $slept, 'Slept 2 seconds for retry_after = 2');
assert_eq(2, count($bot_calls), 'bot() called twice');
assert_eq(0, count($send_message_calls), 'sendMessage() not called');

// 3. 429 with missing parameters.retry_after: defaults to 3s sleep
reset_test_state();
$bot_mock_queue[] = [
    'ok' => false,
    'error_code' => 429,
    'description' => 'Too Many Requests'
];
$bot_mock_queue[] = ['ok' => true, 'result' => ['message_id' => 3]];
$res = sendSuccessResponse(1001, 'active', 'testqr', 'Test Caption', 'Fallback Text', null, 3, $mock_sleeper);
assert_true(isset($res['ok']) && $res['ok'] === true, 'Succeeds on 2nd attempt after 429 missing retry_after');
assert_eq([3], $slept, 'Slept default 3 seconds when retry_after missing');
assert_eq(2, count($bot_calls), 'bot() called twice');

// 4. 429 with non-positive retry_after = 0: defaults to 3s sleep
reset_test_state();
$bot_mock_queue[] = [
    'ok' => false,
    'error_code' => 429,
    'description' => 'Too Many Requests',
    'parameters' => ['retry_after' => 0]
];
$bot_mock_queue[] = ['ok' => true, 'result' => ['message_id' => 4]];
$res = sendSuccessResponse(1001, 'active', 'testqr', 'Test Caption', 'Fallback Text', null, 3, $mock_sleeper);
assert_true(isset($res['ok']) && $res['ok'] === true, 'Succeeds on 2nd attempt after 429 retry_after = 0');
assert_eq([3], $slept, 'Slept default 3 seconds when retry_after = 0');
assert_eq(2, count($bot_calls), 'bot() called twice');

// 5. 429 with retry_after = 6 (> 5s threshold): aborts retries immediately to fallback
reset_test_state();
$bot_mock_queue[] = [
    'ok' => false,
    'error_code' => 429,
    'description' => 'Too Many Requests: retry after 6',
    'parameters' => ['retry_after' => 6]
];
$bot_mock_queue[] = ['ok' => true, 'result' => ['message_id' => 5]]; // would succeed if retried
$res = sendSuccessResponse(1001, 'active', 'testqr', 'Test Caption', 'Fallback Text', null, 3, $mock_sleeper);
assert_eq(0, count($slept), 'Sleeper not called when retry_after exceeds 5s');
assert_eq(1, count($bot_calls), 'Retries aborted immediately on >5s 429');
assert_eq(1, count($send_message_calls), 'sendMessage() fallback called');

// 6. 429 on final retry: does not sleep after final attempt
reset_test_state();
$bot_mock_queue[] = [
    'ok' => false,
    'error_code' => 429,
    'parameters' => ['retry_after' => 2]
];
$bot_mock_queue[] = [
    'ok' => false,
    'error_code' => 429,
    'parameters' => ['retry_after' => 2]
];
$res = sendSuccessResponse(1001, 'active', 'testqr', 'Test Caption', 'Fallback Text', null, 2, $mock_sleeper);
assert_eq([2], $slept, 'Slept once before attempt 2, but not after attempt 2');
assert_eq(2, count($bot_calls), 'bot() attempted retry_count times');
assert_eq(1, count($send_message_calls), 'sendMessage() fallback called');

// 7. Non-429 error (400 bad request): retries immediately without sleeping
reset_test_state();
$bot_mock_queue[] = [
    'ok' => false,
    'error_code' => 400,
    'description' => 'Bad Request: invalid photo url'
];
$bot_mock_queue[] = ['ok' => true, 'result' => ['message_id' => 7]];
$res = sendSuccessResponse(1001, 'active', 'testqr', 'Test Caption', 'Fallback Text', null, 3, $mock_sleeper);
assert_true(isset($res['ok']) && $res['ok'] === true, 'Succeeds on 2nd attempt after 400');
assert_eq(0, count($slept), 'Sleeper not called for non-429 error');
assert_eq(2, count($bot_calls), 'bot() called twice');

// 8. Curl exception: retries immediately without sleeping
reset_test_state();
$bot_mock_queue[] = new Exception('cURL error: connection timed out');
$bot_mock_queue[] = ['ok' => true, 'result' => ['message_id' => 8]];
$res = sendSuccessResponse(1001, 'active', 'testqr', 'Test Caption', 'Fallback Text', null, 3, $mock_sleeper);
assert_true(isset($res['ok']) && $res['ok'] === true, 'Succeeds on 2nd attempt after exception');
assert_eq(0, count($slept), 'Sleeper not called for exception');
assert_eq(2, count($bot_calls), 'bot() called twice');

echo "OK send_success_response_test.php\n";
exit(0);
