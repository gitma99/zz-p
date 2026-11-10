<?php
// Unit tests for renewal_confirm (the renewal money path) and its helpers.
// All helpers are stubbed below; the module must have no top-level side
// effects and no require of its own, so it is safe to include here.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/pure.php'; // convertToBytes() (pure, no side effects)
require_once __DIR__ . '/../../App/functions/renewal_confirm.php';

error_reporting(E_ALL);

// --- Stubs -------------------------------------------------------------

$GLOBALS['stub_rows'] = array();          // db_row: query => row|false
$GLOBALS['stub_exec'] = array();          // db_exec: query => bool
$GLOBALS['exec_calls'] = array();         // db_exec calls: {query,types,params}
$GLOBALS['renewal_api_result'] = null;    // marzban_renewal_api result (null = not called)
$GLOBALS['renewal_api_called'] = false;
$GLOBALS['reset_result'] = array('ok' => true, 'body' => array(), 'error' => null);
$GLOBALS['reset_called'] = false;
$GLOBALS['sent'] = array();               // sendMessage calls
$GLOBALS['delivered'] = array();          // sendSuccessResponse calls
$GLOBALS['delivered_thrown'] = array();   // sendSuccessResponse exceptions
$GLOBALS['deleted'] = array();            // delete_temp_file calls
$GLOBALS['steps'] = array();              // step() calls
$GLOBALS['renewal_state'] = array();      // read_renewal_json value
$GLOBALS['logs'] = array();               // Logger calls: {method, message, context}
$GLOBALS['tx'] = array('begin' => 0, 'commit' => 0, 'rollback' => 0);
$GLOBALS['seq'] = 0;                      // global call sequence number
$GLOBALS['getMe_result'] = array('ok' => true, 'result' => array('username' => 'zzbot'));
$GLOBALS['getMe_thrown'] = array();
$GLOBALS['panel_token'] = 'tok';
$GLOBALS['user'] = array('coin' => 10000);

function _seq() { $GLOBALS['seq']++; return $GLOBALS['seq']; }

class FakeSql {
    public function begin_transaction() { $GLOBALS['tx']['begin']++; return true; }
    public function commit() { $GLOBALS['tx']['commit']++; return true; }
    public function rollback() { $GLOBALS['tx']['rollback']++; return true; }
}

class Logger {
    public static $calls = array();
    public static function __callStatic($m, $a) {
        self::$calls[] = array('method' => $m, 'message' => $a[0], 'context' => isset($a[1]) ? $a[1] : array());
        $GLOBALS['logs'][] = self::$calls[count(self::$calls) - 1];
    }
}

function db_row($sql, $query, $types = '', $params = []) {
    $expected = substr_count($query, '?');
    $actual = count($params);
    if ($expected !== $actual) {
        throw new InvalidArgumentException("db_row: expected {$expected} param(s), {$actual} given for query: {$query}");
    }
    return array_key_exists($query, $GLOBALS['stub_rows']) ? $GLOBALS['stub_rows'][$query] : false;
}

function db_exec($sql, $query, $types = '', $params = []) {
    $expected = substr_count($query, '?');
    $actual = count($params);
    if ($expected !== $actual) {
        throw new InvalidArgumentException("db_exec: expected {$expected} param(s), {$actual} given for query: {$query}");
    }
    $GLOBALS['exec_calls'][] = array('seq' => _seq(), 'query' => $query, 'types' => $types, 'params' => $params);
    return array_key_exists($query, $GLOBALS['stub_exec']) ? $GLOBALS['stub_exec'][$query] : true;
}

function marzban_renewal_api($username, $new_traffic_limit, $new_expire_time, $token, $url) {
    $GLOBALS['renewal_api_called'] = true;
    return $GLOBALS['renewal_api_result'];
}

function marzban_reset_user_usage($username, $token, $url) {
    $GLOBALS['reset_called'] = true;
    return $GLOBALS['reset_result'];
}

function step($s) { $GLOBALS['steps'][] = $s; }

function sendMessage($from_id, $text, $keyboard = null) {
    $GLOBALS['sent'][] = array('seq' => _seq(), 'from_id' => $from_id, 'text' => $text, 'keyboard' => $keyboard);
    return array('ok' => true, 'result' => array('message_id' => 1));
}

function read_renewal_json($user_id) { return $GLOBALS['renewal_state']; }

function delete_temp_file($file_name) { $GLOBALS['deleted'][] = $file_name; return true; }

function getMe() {
    if (!empty($GLOBALS['getMe_thrown'])) {
        throw new Exception('getMe boom');
    }
    return $GLOBALS['getMe_result'];
}

function sendSuccessResponse($from_id, $qr_code_status, $qr_data, $caption_text, $message_text, $start_key, $retry_count = 3, $sleeper = 'sleep') {
    if (!empty($GLOBALS['delivered_thrown'])) {
        throw array_shift($GLOBALS['delivered_thrown']);
    }
    $GLOBALS['delivered'][] = array(
        'seq' => _seq(),
        'from_id' => $from_id,
        'qr_status' => $qr_code_status,
        'qr_data' => $qr_data,
        'caption' => $caption_text,
        'body' => $message_text,
        'start_key' => $start_key,
    );
    return array('ok' => true, 'result' => array('message_id' => 2));
}

function get_marzban_panel_token($panel_name) { return $GLOBALS['panel_token']; }

// --- Case plumbing -----------------------------------------------------

function reset_case() {
    $GLOBALS['stub_rows'] = array();
    $GLOBALS['stub_exec'] = array();
    $GLOBALS['exec_calls'] = array();
    $GLOBALS['renewal_api_result'] = null;
    $GLOBALS['renewal_api_called'] = false;
    $GLOBALS['reset_result'] = array('ok' => true, 'body' => array(), 'error' => null);
    $GLOBALS['reset_called'] = false;
    $GLOBALS['sent'] = array();
    $GLOBALS['delivered'] = array();
    $GLOBALS['delivered_thrown'] = array();
    $GLOBALS['deleted'] = array();
    $GLOBALS['steps'] = array();
    $GLOBALS['renewal_state'] = array();
    $GLOBALS['logs'] = array();
    $GLOBALS['tx'] = array('begin' => 0, 'commit' => 0, 'rollback' => 0);
    $GLOBALS['seq'] = 0;
    $GLOBALS['getMe_result'] = array('ok' => true, 'result' => array('username' => 'zzbot'));
    $GLOBALS['getMe_thrown'] = array();
    $GLOBALS['panel_token'] = 'tok';
    $GLOBALS['user'] = array('coin' => 10000);
    $GLOBALS['texts'] = texts_stub();
    $GLOBALS['my_texts'] = $GLOBALS['texts'];
    $GLOBALS['start_key'] = 'key';
    $GLOBALS['sql'] = new FakeSql();
    Logger::$calls = array();
}

function texts_stub() {
    return array(
        'create_service_proccess' => '<b>processing</b>',
        'renew_error' => '%s | RENEW ERROR',
        'not_coin' => 'NOT COIN %s',
        'choice_error' => 'choice error',
        'renewal_service_config_not_found' => 'renewal config not found',
        'qr_unavailable_hint' => 'QR hint',
        'renew_persist_failed' => 'persist failed: %s',
        'success_create_service' => 'CC %s|%s|%s|%s|%s|%s|%s',
        'service_factor' => 'factor %s|%s|%s|%s|%s',
        'error_show_service__token_is_incorrect' => 'token bad',
    );
}

function case_setup() {
    reset_case();
    $GLOBALS['stub_rows']['SELECT * FROM `orders` WHERE `code` = ? AND `from_id` = ? LIMIT 1']
        = array('location' => 'srv1', 'type' => 'marzban');
    $GLOBALS['stub_rows']['SELECT * FROM `category` WHERE `name` = ? LIMIT 1']
        = array('limit' => '100', 'date' => '30');
    $GLOBALS['stub_rows']['SELECT `coin` FROM `users` WHERE `from_id` = ? LIMIT 1 FOR UPDATE']
        = array('coin' => 10000);
    $GLOBALS['stub_rows']['SELECT * FROM `panels` WHERE `name` = ? LIMIT 1']
        = array('name' => 'srv1', 'type' => 'marzban', 'login_link' => 'https://p', 'token' => 't', 'qr_code' => 'active');
    $GLOBALS['renewal_state'] = array(
        'config_name' => 'svc_7',
        'config_base_name' => 'svc',
        'new_plan' => 'gold',
        'new_plan_price' => 5000,
        'new_server' => 'srv1',
    );
    $GLOBALS['renewal_api_result'] = array(
        'ok' => true,
        'body' => array(
            'username' => 'svc_7',
            'links' => array('vless://a', 'vless://b'),
            'subscription_url' => '/sub/u',
        ),
        'error' => null,
    );
}

function sent_texts() {
    $out = array();
    foreach ($GLOBALS['sent'] as $s) $out[] = $s['text'];
    return $out;
}

function assert_sent($needle, $msg) {
    assert_true(in_array($needle, sent_texts(), true), $msg . " (got: " . json_encode(sent_texts()) . ")");
}

function assert_log($level, $message, $msg, $ctx_key = null, $ctx_val = null) {
    foreach ($GLOBALS['logs'] as $l) {
        if ($l['method'] === $level && $l['message'] === $message) {
            if ($ctx_key !== null && ($l['context'][$ctx_key] ?? null) !== $ctx_val) continue;
            return;
        }
    }
    fail("$msg (logs: " . json_encode($GLOBALS['logs']) . ")");
}

function cc() { return $GLOBALS['renewal_api_called']; }
function dlv() { return $GLOBALS['delivered']; }
function txg() { return $GLOBALS['tx']; }

// --- 1. missing state ---------------------------------------------------

case_setup();
$GLOBALS['renewal_state'] = array();
renewal_confirm(7);
assert_eq(array('none'), $GLOBALS['steps'], 'case1: step(none) called first');
assert_sent('renewal config not found', 'case1: config not found message');
assert_true(!cc(), 'case1: no API call');
assert_eq(0, txg()['begin'], 'case1: no tx begin');
assert_log('warning', 'renewal_state_missing', 'case1: WARNING log renewal_state_missing', 'from_id', 7);

// --- 2. invalid price ----------------------------------------------------

case_setup();
$GLOBALS['renewal_state']['new_plan_price'] = 'abc';
renewal_confirm(7);
assert_sent('1 | RENEW ERROR', 'case2: renew_error with 1');
assert_true(!cc(), 'case2: no API call');
assert_eq(0, count(dlv()), 'case2: nothing delivered');
assert_log('error', 'renewal_bad_price', 'case2: ERROR log for bad price', 'from_id', 7);

// --- 3. order missing -----------------------------------------------------

case_setup();
$GLOBALS['stub_rows']['SELECT * FROM `orders` WHERE `code` = ? AND `from_id` = ? LIMIT 1'] = false;
renewal_confirm(7);
assert_sent('2 | RENEW ERROR', 'case3: renew_error with 2');
assert_true(!cc(), 'case3: no API call');
assert_log('error', 'renewal_order_missing', 'case3: ERROR log renewal_order_missing', 'from_id', 7);

// --- 4. non-marzban order --------------------------------------------------

case_setup();
$GLOBALS['stub_rows']['SELECT * FROM `orders` WHERE `code` = ? AND `from_id` = ? LIMIT 1']
    = array('location' => 'srv1', 'type' => 'sanayi');
renewal_confirm(7);
assert_sent('2 | RENEW ERROR', 'case4: renew_error with 2');
assert_true(!cc(), 'case4: no API call');
assert_log('error', 'renewal_not_marzban', 'case4: ERROR log renewal_not_marzban', 'from_id', 7);

// --- 5. panel mismatch ------------------------------------------------------

case_setup();
$GLOBALS['renewal_state']['new_server'] = 'srv2';
renewal_confirm(7);
assert_sent('2 | RENEW ERROR', 'case5: renew_error with 2');
assert_true(!cc(), 'case5: no API call');
assert_log('error', 'renewal_panel_mismatch', 'case5: ERROR log renewal_panel_mismatch', 'from_id', 7);

// --- 6. plan missing ----------------------------------------------------------

case_setup();
$GLOBALS['stub_rows']['SELECT * FROM `category` WHERE `name` = ? LIMIT 1'] = false;
renewal_confirm(7);
assert_sent('0 | RENEW ERROR', 'case6: renew_error with 0');
assert_true(!cc(), 'case6: no API call');
assert_log('error', 'renewal_plan_missing', 'case6: ERROR log renewal_plan_missing', 'from_id', 7);

// --- 7a. panel missing ----------------------------------------------------------

case_setup();
$GLOBALS['stub_rows']['SELECT * FROM `panels` WHERE `name` = ? LIMIT 1'] = false;
renewal_confirm(7);
assert_sent('2 | RENEW ERROR', 'case7a: renew_error with 2');
assert_true(!cc(), 'case7a: no API call');
assert_log('error', 'renewal_panel_missing', 'case7a: ERROR log renewal_panel_missing', 'from_id', 7);

// --- 7b. non-marzban panel --------------------------------------------------------

case_setup();
$GLOBALS['stub_rows']['SELECT * FROM `panels` WHERE `name` = ? LIMIT 1']['type'] = 'sanayi';
renewal_confirm(7);
assert_sent('Not implanted Error', 'case7b: existing "Not implanted Error" message');
assert_true(!cc(), 'case7b: no API call');
assert_true(count(dlv()) === 0, 'case7b: nothing delivered');

// --- 8. coin short ------------------------------------------------------------------

case_setup();
$GLOBALS['user']['coin'] = 100;
renewal_confirm(7);
assert_sent('NOT COIN 5,000', 'case8: not_coin message');
assert_true(!cc(), 'case8: no API call');
assert_eq(0, txg()['begin'], 'case8: no tx begin');

// --- 9. token failure -----------------------------------------------------------------

case_setup();
$GLOBALS['panel_token'] = false;
renewal_confirm(7);
assert_sent('token bad', 'case9: token incorrect message');
assert_true(!cc(), 'case9: no API call');
assert_eq(0, txg()['begin'], 'case9: no tx begin');
assert_log('error', 'renewal_token_failed', 'case9: ERROR log renewal_token_failed', 'from_id', 7);

// --- 10. API failure ---------------------------------------------------------------------

case_setup();
$GLOBALS['renewal_api_result'] = array('ok' => false, 'body' => null, 'error' => 'detail');
renewal_confirm(7);
assert_sent('1 | RENEW ERROR', 'case10: renew_error with 1');
assert_true($GLOBALS['reset_called'] === false, 'case10: no reset call');
assert_eq(0, txg()['begin'], 'case10: no tx begin');
assert_log('error', 'renewal_api_failed', 'case10: ERROR log renewal_api_failed', 'from_id', 7);

// --- 11. accepted but incomplete body -----------------------------------------------------

case_setup();
$GLOBALS['renewal_api_result'] = array('ok' => true, 'body' => array('username' => 'svc_7'), 'error' => null);
renewal_confirm(7);
assert_eq(1, count(dlv()), 'case11: delivered anyway');
assert_eq('active', dlv()[0]['qr_status'], 'case11: qr status passed');
assert_eq('', dlv()[0]['qr_data'], 'case11: empty subscribe URL');
assert_eq('CC svc_7|srv1|30|100|5,000||@zzbot', dlv()[0]['caption'], 'case11: caption without sub URL');
$coin_exec = null;
foreach ($GLOBALS['exec_calls'] as $c) {
    if (strpos($c['query'], 'UPDATE `users`') === 0) $coin_exec = $c;
}
assert_true($coin_exec !== null, 'case11: coin UPDATE executed');
assert_eq(array(5000, 7), $coin_exec['params'], 'case11: coin charged the frozen state price');
assert_eq(1, txg()['commit'], 'case11: tx committed');
assert_log('warning', 'renewal_api_incomplete', 'case11: WARNING log renewal_api_incomplete', 'from_id', 7);

// --- 12. reset failure -----------------------------------------------------------------------

case_setup();
$GLOBALS['reset_result'] = array('ok' => false, 'body' => null, 'error' => 'boom');
renewal_confirm(7);
assert_eq(1, count(dlv()), 'case12: still delivered');
assert_eq('active', dlv()[0]['qr_status'], 'case12: qr status passed');
assert_eq('https://p/sub/u', dlv()[0]['qr_data'], 'case12: sub URL resolved');
assert_eq(1, txg()['commit'], 'case12: tx committed');
assert_log('warning', 'renewal_reset_failed', 'case12: WARNING log renewal_reset_failed', 'from_id', 7);

// --- 13. persistence failure (coin UPDATE fails) -------------------------------------------------

case_setup();
$GLOBALS['stub_exec']['UPDATE `users` SET `coin` = coin - ? WHERE `from_id` = ? LIMIT 1'] = false;
renewal_confirm(7);
assert_eq(1, txg()['rollback'], 'case13: tx rolled back');
assert_eq(0, txg()['commit'], 'case13: no commit');
assert_sent('persist failed: svc', 'case13: renew_persist_failed text with code');
assert_eq(0, count(dlv()), 'case13: no sendSuccessResponse');
assert_eq(array(), $GLOBALS['deleted'], 'case13: no file deleted');
assert_log('error', 'renewal_persist_failed', 'case13: ERROR log renewal_persist_failed', 'from_id', 7);
$orders_exec = null;
foreach ($GLOBALS['exec_calls'] as $c) {
    if (strpos($c['query'], 'UPDATE `orders`') === 0) $orders_exec = $c;
}
assert_true($orders_exec !== null, 'case13: orders UPDATE attempted inside tx');
assert_eq(array('srv1', '30', '100', "vless://a\n\nvless://b", 5000, 'svc', 7), $orders_exec['params'], 'case13: orders params');

// --- 13b. persistence failure (balance row missing) --------------------------------------------------

case_setup();
$GLOBALS['stub_rows']['SELECT `coin` FROM `users` WHERE `from_id` = ? LIMIT 1 FOR UPDATE'] = false;
renewal_confirm(7);
assert_eq(1, txg()['rollback'], 'case13b: tx rolled back on missing balance row');
assert_sent('persist failed: svc', 'case13b: renew_persist_failed text with code');
assert_eq(0, count(dlv()), 'case13b: nothing delivered');
assert_eq(array(), $GLOBALS['deleted'], 'case13b: no file deleted');

// --- 14. delivery failure --------------------------------------------------------------------------

case_setup();
$GLOBALS['delivered_thrown'][] = new Exception('tg down');
renewal_confirm(7);
assert_sent('QR hint', 'case14: qr_unavailable_hint message');
assert_eq(1, txg()['commit'], 'case14: commit already happened');
assert_eq(array('renewal-service-7.json'), $GLOBALS['deleted'], 'case14: file is deleted');
assert_log('error', 'renewal_delivery_failed', 'case14: ERROR log renewal_delivery_failed', 'from_id', 7);

// --- 15. success (the big one) ------------------------------------------------------------------------

case_setup();
$GLOBALS['getMe_thrown'] = array('yes'); // getMe() throws: username falls back to '*'
renewal_confirm(7);
assert_eq(array('none'), $GLOBALS['steps'], 'case15: steps == [none] (one call, first)');
assert_eq(array('begin' => 1, 'commit' => 1, 'rollback' => 0), txg(), 'case15: tx begin 1 commit 1 rollback 0');
assert_eq(array('renewal-service-7.json'), $GLOBALS['deleted'], 'case15: state file deleted');
assert_eq(1, count(dlv()), 'case15: delivered exactly once');
// delivery must come after the whole transaction: assert it is sequenced
// after the last db_exec (the coin UPDATE is the final tx statement)
$last_exec_seq = max(array_map(function ($c) { return $c['seq']; }, $GLOBALS['exec_calls']));
assert_true(dlv()[0]['seq'] > $last_exec_seq, 'case15: delivery after commit (after last tx exec)');
assert_log('warning', 'renewal_getme_failed', 'case15: WARNING log renewal_getme_failed');
assert_log('info', 'renewal_completed', 'case15: INFO log renewal_completed', 'from_id', 7);

$orders_call = null; $coin_exec = null;
foreach ($GLOBALS['exec_calls'] as $c) {
    if (strpos($c['query'], 'UPDATE `orders`') === 0) $orders_call = $c;
    if (strpos($c['query'], 'UPDATE `users`') === 0) $coin_exec = $c;
}
assert_true($orders_call !== null && $coin_exec !== null, 'case15: both UPDATEs executed');
assert_eq(array('srv1', '30', '100', "vless://a\n\nvless://b", 5000, 'svc', 7), $orders_call['params'], 'case15: orders params');
assert_eq('sssssss', $orders_call['types'], 'case15: orders types');
assert_eq(array(5000, 7), $coin_exec['params'], 'case15: coin params');
assert_eq('ss', $coin_exec['types'], 'case15: coin types');
assert_true($orders_call['seq'] < $coin_exec['seq'], 'case15: orders UPDATE before coin UPDATE');
assert_eq('CC svc_7|srv1|30|100|5,000||@*', dlv()[0]['caption'], 'case15: caption uses full code and @* fallback');
assert_eq('CC svc|srv1|30|100|5,000|https://p/sub/u|@*', dlv()[0]['body'], 'case15: body uses base code and sub URL');
assert_eq(7, dlv()[0]['from_id'], 'case15: delivered to from_id');
assert_eq('active', dlv()[0]['qr_status'], 'case15: qr status');
assert_eq('https://p/sub/u', dlv()[0]['qr_data'], 'case15: subscribe URL resolved');
assert_eq('key', dlv()[0]['start_key'], 'case15: start key passed');
assert_sent('<b>processing</b>', 'case15: proccess message sent');
$proc = null;
foreach ($GLOBALS['sent'] as $s) { if ($s['text'] === '<b>processing</b>') $proc = $s; }
assert_true($proc !== null && $proc['seq'] < dlv()[0]['seq'], 'case15: proccess message before delivery');

// --- 16. renewal_state_missing() units --------------------------------------------------------------------

assert_eq(array(), renewal_state_missing(array(
    'config_name' => 'a', 'config_base_name' => 'b', 'new_plan' => 'c', 'new_plan_price' => 0, 'new_server' => 'd',
)), 'case16: full state (price 0 is not missing)');
assert_eq(array('new_plan'), renewal_state_missing(array(
    'config_name' => 'a', 'config_base_name' => 'b', 'new_plan' => '', 'new_plan_price' => 5, 'new_server' => 'd',
)), 'case16: empty-string value counts as missing');
assert_eq(
    array('config_name', 'config_base_name', 'new_plan', 'new_plan_price', 'new_server'),
    renewal_state_missing(null),
    'case16: null input => all five'
);
assert_eq(
    array('config_name', 'config_base_name', 'new_plan', 'new_plan_price', 'new_server'),
    renewal_state_missing('nope'),
    'case16: non-array input => all five'
);
assert_eq(array('config_name'), renewal_state_missing(array(
    'config_base_name' => 'b', 'new_plan' => 'c', 'new_plan_price' => 1, 'new_server' => 'd',
)), 'case16: absent key reported');

// --- 17. helper units ---------------------------------------------------------------------------------------

assert_eq("vless://a\n\nvless://b", renewal_links_text(array('links' => array('vless://a', 'vless://b'))), 'case17: links joined');
assert_eq('', renewal_links_text(array('username' => 'u')), 'case17: no links key => empty');
assert_eq('', renewal_links_text(null), 'case17: non-array body => empty');
assert_eq('', renewal_links_text(array('links' => 'x')), 'case17: links not array => empty');

assert_eq('https://sub/u', renewal_subscribe_url('https://sub/u', 'https://p'), 'case17: absolute URL verbatim');
assert_eq('https://p/sub/u', renewal_subscribe_url('/sub/u', 'https://p'), 'case17: relative URL prefixed');
assert_eq('', renewal_subscribe_url('', 'https://p'), 'case17: empty URL => empty');

echo "OK renewal_confirm_test.php\n";
exit(0);
