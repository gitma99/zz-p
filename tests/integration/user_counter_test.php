<?php
// Integration test: recompute_user_service_count() against a real MySQL database.
// Requires a live MySQL server (MYSQL_HOST/PORT/USER/PASS/DB, .env.test fallback).
require_once __DIR__ . '/../unit/helper.php';
require_once __DIR__ . '/../../App/Utils/db_tools.php';
require_once __DIR__ . '/../../App/Utils/user_counter.php';
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
$link = null;
for ($attempt = 1; $attempt <= 10; $attempt++) {
    $link = @new mysqli($host, $user, $pass, 'mysql', $port);
    if (!$link->connect_error) {
        break;
    }
    sleep(1);
}
if (!$link || $link->connect_error) {
    fail("cannot connect to MySQL server: " . ($link ? $link->connect_error : "connection failed"));
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
        @$mysqli->query("DROP TABLE IF EXISTS users");
        @$mysqli->query("DROP TABLE IF EXISTS orders");
        @$mysqli->close();
    }
    $dropLink = @new mysqli($host, $user, $pass, 'mysql', $port);
    if (!$dropLink->connect_error) {
        @$dropLink->query("DROP DATABASE IF EXISTS $db");
        @$dropLink->close();
    }
});

// --- Setup: throwaway tables mirroring the real column types ---
$mysqli->query("DROP TABLE IF EXISTS orders");
$mysqli->query("DROP TABLE IF EXISTS users");
$mysqli->query("CREATE TABLE users (`row` INT AUTO_INCREMENT PRIMARY KEY, from_id BIGINT NOT NULL, count_service INT DEFAULT 0)");
$mysqli->query("CREATE TABLE orders (`row` INT AUTO_INCREMENT PRIMARY KEY, from_id VARCHAR(20) NOT NULL, code VARCHAR(20) NOT NULL)");
$mysqli->query("INSERT INTO users (from_id, count_service) VALUES (1001, 0), (1002, 0)");
$mysqli->query("INSERT INTO orders (from_id, code) VALUES ('1001', 'a'), ('1001', 'b')");

// --- 1. User with 2 orders -> count becomes 2 ---
assert_eq(true, recompute_user_service_count($mysqli, '1001'), 'recompute returns true for user with orders');
$row = db_row($mysqli, 'SELECT count_service FROM users WHERE from_id = ?', 's', ['1001']);
assert_eq(2, (int)$row['count_service'], 'count_service is 2 after first recompute');

// --- 2. User with no orders -> count becomes 0 ---
assert_eq(true, recompute_user_service_count($mysqli, '1002'), 'recompute returns true for user without orders');
$row = db_row($mysqli, 'SELECT count_service FROM users WHERE from_id = ?', 's', ['1002']);
assert_eq(0, (int)$row['count_service'], 'count_service is 0 for user without orders');

// --- 3. New order -> count grows to 3 ---
$mysqli->query("INSERT INTO orders (from_id, code) VALUES ('1001', 'c')");
assert_eq(true, recompute_user_service_count($mysqli, '1001'), 'recompute returns true after order added');
$row = db_row($mysqli, 'SELECT count_service FROM users WHERE from_id = ?', 's', ['1001']);
assert_eq(3, (int)$row['count_service'], 'count_service is 3 after third order');

// --- 4. No users row for from_id -> UPDATE matches 0 rows, still true, no error ---
assert_eq(true, recompute_user_service_count($mysqli, '9999'), 'recompute returns true when no users row exists');

// --- Teardown ---
$mysqli->query("DROP TABLE IF EXISTS users");
$mysqli->query("DROP TABLE IF EXISTS orders");
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

echo "OK user_counter_test.php\n";
exit(0);
