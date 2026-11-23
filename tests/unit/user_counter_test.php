<?php
// Unit test: recompute_user_service_count() — defined in App/Utils/user_counter.php.
// Self-contained: stubs db_row/db_exec so no database connection is needed.
// Stubs mirror the real driver contract (param count vs '?') per AGENTS.md.
require_once __DIR__ . '/helper.php';
error_reporting(E_ALL);

// --- Stubs -------------------------------------------------------------

$GLOBALS['row_null'] = false;       // true = db_row returns null (no row / DB failure)
$GLOBALS['stub_row'] = null;        // db_row return override (set when row_null is false)
$GLOBALS['row_calls'] = array();    // db_row calls: {query, types, params}
$GLOBALS['exec_result'] = true;     // db_exec return value
$GLOBALS['exec_calls'] = array();   // db_exec calls: {query, types, params}

function db_row($sql, $query, $types = '', $params = []) {
    $expected = substr_count($query, '?');
    $actual = count($params);
    if ($expected !== $actual) {
        throw new InvalidArgumentException("db_row: expected {$expected} param(s), {$actual} given for query: {$query}");
    }
    $GLOBALS['row_calls'][] = array('query' => $query, 'types' => $types, 'params' => $params);
    if ($GLOBALS['row_null']) return null;
    if ($GLOBALS['stub_row'] !== null) return $GLOBALS['stub_row'];
    return array('n' => 3);
}

function db_exec($sql, $query, $types = '', $params = []) {
    $expected = substr_count($query, '?');
    $actual = count($params);
    if ($expected !== $actual) {
        throw new InvalidArgumentException("db_exec: expected {$expected} param(s), {$actual} given for query: {$query}");
    }
    $GLOBALS['exec_calls'][] = array('query' => $query, 'types' => $types, 'params' => $params);
    return $GLOBALS['exec_result'];
}

require_once __DIR__ . '/../../App/Utils/user_counter.php';

// --- Cases ---------------------------------------------------------------

// 1. Success: true; exactly one db_exec with the exact UPDATE, types 'is', int count + string from_id
assert_eq(true, recompute_user_service_count(null, '1001'), 'success returns true');
assert_eq(1, count($GLOBALS['exec_calls']), 'success: exactly one db_exec call');
assert_eq(
    'UPDATE `users` SET `count_service` = ? WHERE `from_id` = ? LIMIT 1',
    $GLOBALS['exec_calls'][0]['query'],
    'success: UPDATE query'
);
assert_eq('is', $GLOBALS['exec_calls'][0]['types'], 'success: types "is"');
assert_eq(array(3, '1001'), $GLOBALS['exec_calls'][0]['params'], 'success: params [int count, string from_id]');

// 2. Count query shape: exact SELECT, types 's', params [from_id]
assert_eq(1, count($GLOBALS['row_calls']), 'success: exactly one db_row call');
assert_eq(
    'SELECT COUNT(*) AS `n` FROM `orders` WHERE `from_id` = ?',
    $GLOBALS['row_calls'][0]['query'],
    'count: SELECT query'
);
assert_eq('s', $GLOBALS['row_calls'][0]['types'], 'count: types "s"');
assert_eq(array('1001'), $GLOBALS['row_calls'][0]['params'], 'count: params [from_id]');

// 3. db_row null -> false, no UPDATE attempted
$GLOBALS['row_calls'] = array();
$GLOBALS['exec_calls'] = array();
$GLOBALS['stub_row'] = null;
$GLOBALS['row_null'] = true;
assert_eq(false, recompute_user_service_count(null, '1001'), 'db_row null returns false');
$GLOBALS['row_null'] = false;
assert_eq(1, count($GLOBALS['row_calls']), 'db_row null: db_row was called');
assert_eq(0, count($GLOBALS['exec_calls']), 'db_row null: no db_exec call');

// 4. db_exec false -> false, one exec call recorded
$GLOBALS['row_calls'] = array();
$GLOBALS['exec_calls'] = array();
$GLOBALS['stub_row'] = array('n' => 5);
$GLOBALS['exec_result'] = false;
assert_eq(false, recompute_user_service_count(null, '2002'), 'db_exec false returns false');
assert_eq(1, count($GLOBALS['exec_calls']), 'db_exec false: one db_exec call');
assert_eq(array(5, '2002'), $GLOBALS['exec_calls'][0]['params'], 'db_exec false: params still [int count, from_id]');

echo "OK user_counter_test.php\n";
exit(0);
