<?php
// Unit tests for TelegramHandler::defaultSender: failure spooling, maintainer guard, extra recipient.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/handlers/TelegramHandler.php';

$GLOBALS['sendFile_calls'] = [];
$GLOBALS['sendFile_throw'] = null;
function sendFile($chat_id, $file_path, $file_name, $mime_type, $api_key = null, $caption = null) {
    global $sendFile_calls, $sendFile_throw;
    $sendFile_calls[] = ['chat_id' => $chat_id, 'file_name' => $file_name];
    if ($sendFile_throw !== null) throw $sendFile_throw;
    return ['ok' => true];
}

$errFile = sys_get_temp_dir() . '/logger_tg_sender_err.log';
$spoolDir = sys_get_temp_dir() . '/logger_tg_spool';
@unlink($errFile);
array_map('unlink', glob($spoolDir . '/*') ?: []);
ini_set('error_log', $errFile); // CLI: error_log() without destination writes here

function reset_sender_state() { global $sendFile_calls, $sendFile_throw; $sendFile_calls = []; $sendFile_throw = null; }

// 1. Success: single sendFile call, no spool, no error log
reset_sender_state();
$tmp = tempnam(sys_get_temp_dir(), 'tg'); file_put_contents($tmp, '{"level":"ERROR"}');
$sender = TelegramHandler::defaultSender('123', 'k');
$sender($tmp, 'ok.json', '[ERROR] ok');
assert_eq(1, count($sendFile_calls), 'sendFile called once on success');
assert_eq(0, count(glob($spoolDir . '/*') ?: []), 'No spool on success');
assert_eq(false, file_exists($errFile), 'No error_log on success');
@unlink($tmp);

// 2. Failure: spool copy with same content + error_log containing the Telegram error
reset_sender_state();
$sendFile_throw = new Exception('{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}');
$tmp = tempnam(sys_get_temp_dir(), 'tg'); file_put_contents($tmp, '{"level":"ERROR","msg":"boom"}');
$sender($tmp, 'fail.json', '[ERROR] boom');
$spooled = glob($spoolDir . '/fail.json');
assert_eq(1, count($spooled), 'Failed record spooled');
assert_eq(file_get_contents($tmp), file_get_contents($spooled[0]), 'Spool copy content matches record');
assert_true(strpos((string)@file_get_contents($errFile), 'chat not found') !== false, 'Error log contains Telegram error message');
@unlink($tmp);

// 3. Empty maintainer id: sendFile never called, drop logged
reset_sender_state();
$tmp = tempnam(sys_get_temp_dir(), 'tg'); file_put_contents($tmp, '{"level":"ERROR"}');
TelegramHandler::defaultSender(null, 'k')($tmp, 'null.json', '[ERROR] no maintainer');
assert_eq(0, count($sendFile_calls), 'sendFile not called when maintainer id is empty');
assert_true(strpos((string)@file_get_contents($errFile), 'maintainer id not configured') !== false, 'Drop logged when maintainer id is empty');
@unlink($tmp);

// 4. Extra recipient (testmenu copy): both recipients get the file
reset_sender_state();
$tmp = tempnam(sys_get_temp_dir(), 'tg'); file_put_contents($tmp, '{"level":"ERROR"}');
TelegramHandler::defaultSender('111', 'k')($tmp, 'extra.json', '[ERROR] both', '222');
assert_eq(2, count($sendFile_calls), 'sendFile called for maintainer and extra chat');
assert_eq('111', $sendFile_calls[0]['chat_id'], 'Maintainer sent first');
assert_eq('222', $sendFile_calls[1]['chat_id'], 'Extra chat sent second');
@unlink($tmp);

// 5. Extra recipient failure: spooled too, maintainer copy unaffected
reset_sender_state();
$sendFile_throw = new Exception('extra failed');
$tmp = tempnam(sys_get_temp_dir(), 'tg'); file_put_contents($tmp, '{"level":"ERROR"}');
$spooledBefore = count(glob($spoolDir . '/*') ?: []);
TelegramHandler::defaultSender('111', 'k')($tmp, 'extrafail.json', '[ERROR] extra fails', '222');
assert_eq($spooledBefore + 1, count(glob($spoolDir . '/*') ?: []), 'Extra recipient failure spooled once');
assert_eq(2, count($sendFile_calls), 'Extra recipient still attempted after maintainer failure');
@unlink($tmp);

// cleanup
array_map('unlink', glob($spoolDir . '/*') ?: []);
rmdir($spoolDir);
@unlink($errFile);

echo "OK telegram_handler_sender_test.php\n";
exit(0);
