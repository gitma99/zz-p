<?php
// Unit tests for marzban_parse_api_result (pure; no network).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/apis/marzban-api/api.php';

$valid = json_encode(array('username' => 'u_1', 'links' => array('vless://x'), 'subscription_url' => 'https://sub/x'));

$r = marzban_parse_api_result(false, 'timed out');
assert_eq(false, $r['ok'], 'curl failure is not ok');
assert_eq('timed out', $r['error'], 'curl failure carries the curl error');

$r = marzban_parse_api_result('not json');
assert_eq(false, $r['ok'], 'non-JSON is not ok');
assert_eq('invalid response', $r['error'], 'non-JSON error text');
assert_eq(null, $r['body'], 'non-JSON body is null');

$r = marzban_parse_api_result(json_encode(array('detail' => 'User not found')));
assert_eq(false, $r['ok'], 'detail body is not ok');
assert_eq('User not found', $r['error'], 'detail surfaced as error');
assert_eq(array('detail' => 'User not found'), $r['body'], 'decodable body kept');

$r = marzban_parse_api_result(json_encode(array('foo' => 'bar')));
assert_eq(false, $r['ok'], 'body without username is not ok');

$r = marzban_parse_api_result($valid);
assert_eq(true, $r['ok'], 'valid body is ok');
assert_eq(null, $r['error'], 'valid body has no error');
assert_eq('u_1', $r['body']['username'], 'valid body decoded');

echo "OK marzban_api_result_test.php\n";
exit(0);
