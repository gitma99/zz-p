<?php
// Integration test: db_row() and db_exec() against a real MySQL database.
// Defined in App/Utils/db_tools.php. Requires a live MySQL server.
require_once __DIR__ . '/../unit/helper.php';
require_once __DIR__ . '/../../App/Utils/db_tools.php';
error_reporting(E_ALL ^ E_NOTICE);

// --- Load .env.test from repo root (env vars take precedence) ---
$envFile = __DIR__ . '/../../.env.test';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), '"\'');
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MYSQL_PORT') ?: '3306');
$user = getenv('MYSQL_USER') ?: 'root';
$pass = getenv('MYSQL_PASS') ?: '';
$db   = getenv('MYSQL_DB') ?: 'zzpanel_explain_test';

// Basic safety: only allow identifier-safe database names.
if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
    fail("unsafe MYSQL_DB value: $db");
}

// Ensure the test database exists before connecting to it. Each integration
// test is responsible for its own DB lifecycle; CI only runs the test files.
$link = new mysqli($host, $user, $pass, 'mysql', $port);
if ($link->connect_error) {
    fail("cannot connect to MySQL server: " . $link->connect_error);
}
if (!$link->query("CREATE DATABASE IF NOT EXISTS $db")) {
    fail("cannot create database $db: " . $link->error);
}
$link->close();

$mysqli = new mysqli($host, $user, $pass, $db, $port);
if ($mysqli->connect_error) {
    fail("cannot connect to MySQL: " . $mysqli->connect_error);
}

// Guaranteed teardown on success or early exit/failure.
register_shutdown_function(function() use ($host, $user, $pass, $port, $db, &$mysqli) {
    if ($mysqli instanceof mysqli && !$mysqli->connect_errno) {
        @$mysqli->query("DROP TABLE IF EXISTS db_tools_test");
        @$mysqli->close();
    }
    $dropLink = @new mysqli($host, $user, $pass, 'mysql', $port);
    if (!$dropLink->connect_error) {
        @$dropLink->query("DROP DATABASE IF EXISTS $db");
        @$dropLink->close();
    }
});

$failures = 0;

// --- Setup: create throwaway table ---
$mysqli->query("DROP TABLE IF EXISTS db_tools_test");
$mysqli->query("CREATE TABLE db_tools_test (id INT PRIMARY KEY, name VARCHAR(50), value INT)");
$mysqli->query("INSERT INTO db_tools_test (id, name, value) VALUES (1, 'alpha', 100)");

// --- db_row tests ---

// Success
$row = db_row($mysqli, 'SELECT name, value FROM db_tools_test WHERE id = ?', 'i', [1]);
assert_eq(['name' => 'alpha', 'value' => 100], $row, 'db_row success');

// Prepare fails
$result = db_row($mysqli, 'SELEC * FROM db_tools_test WHERE id = ?', 'i', [1]);
assert_eq(null, $result, 'db_row prepare fails -> null');

// Execute fails (subquery returns more than 1 row)
$mysqli->query("INSERT INTO db_tools_test (id, name, value) VALUES (2, 'beta', 200)");
$result = db_row($mysqli, 'SELECT * FROM db_tools_test WHERE id = ? AND (SELECT id FROM db_tools_test) = 1', 'i', [1]);
assert_eq(null, $result, 'db_row execute fails -> null');

// Empty result
$row = db_row($mysqli, 'SELECT * FROM db_tools_test WHERE id = ?', 'i', [999]);
assert_eq(null, $row, 'db_row empty result -> null');

// Parameterless db_row
$row = db_row($mysqli, 'SELECT COUNT(*) AS total FROM db_tools_test');
assert_eq('2', (string)$row['total'], 'db_row parameterless query');

// --- db_exec tests ---

// Success
$result = db_exec($mysqli, 'INSERT INTO db_tools_test (id, name, value) VALUES (?, ?, ?)', 'iis', [3, 'gamma', 300]);
assert_eq(true, $result, 'db_exec success');

// Parameterless db_exec
$result = db_exec($mysqli, 'UPDATE db_tools_test SET value = value + 1 WHERE id = 1');
assert_eq(true, $result, 'db_exec parameterless query');
$row = db_row($mysqli, 'SELECT value FROM db_tools_test WHERE id = ?', 'i', [1]);
assert_eq(101, (int)$row['value'], 'db_exec parameterless updated value');

// Prepare fails
$result = db_exec($mysqli, 'INSER * FROM db_tools_test WHERE id = ?', 'i', [1]);
assert_eq(false, $result, 'db_exec prepare fails -> false');

// Execute fails (duplicate key)
$result = db_exec($mysqli, 'INSERT INTO db_tools_test (id, name, value) VALUES (?, ?, ?)', 'iis', [1, 'dup', 999]);
assert_eq(false, $result, 'db_exec execute fails -> false');

// --- Teardown ---
$mysqli->query("DROP TABLE IF EXISTS db_tools_test");
$mysqli->close();

// Drop the test database so no state leaks into subsequent integration tests.
$dropLink = new mysqli($host, $user, $pass, 'mysql', $port);
if ($dropLink->connect_error) {
    fail("cannot connect to MySQL server for database teardown: " . $dropLink->connect_error);
}
if (!$dropLink->query("DROP DATABASE IF EXISTS $db")) {
    fail("cannot drop database $db: " . $dropLink->error);
}
$dropLink->close();

echo "OK db_tools_test.php\n";
exit(0);
