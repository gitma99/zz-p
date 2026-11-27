<?php

// sql/sql.php — thin compatibility shim (CLI-only).
//
// All schema creation now lives in versioned migration files under
// sql/migrations/ and is applied by tools/run-migrations.php on every boot,
// install, and update. This file no longer creates tables or indexes — it
// only ensures the schema_migrations tracker table exists (defensive; the
// runner also creates it) and returns a JSON status.
//
// Credentials are read from the DB_HOST / DB_USER / DB_PASS / DB_NAME
// environment variables; HTTP access is no longer supported.

if (php_sapi_name() !== 'cli') {
    http_response_code(410);
    die(json_encode(['status' => false, 'msg' => 'HTTP access removed; run from CLI', 'status_code' => 410], 448));
}

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_name = getenv('DB_NAME');

if ($db_user === false || $db_name === false) {
    echo json_encode(['status' => false, 'msg' => 'Database operation failed -> missing credentials', 'error_code' => 401], 448);
    exit(1);
}

$sql = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($sql->connect_error) {
    echo json_encode(['status' => false, 'msg' => '❌ The connection with the database encountered an error : ' . $sql->connect_error, 'status_code' => 401], 448);
    exit(1);
}

$sql->set_charset("utf8mb4");

// Defensive: ensure the migration tracker table exists even if this file is
// run without the migration runner. The runner applies all versioned
// migrations (init + indexes) right after this on every boot/install/update.
$sql->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `filename` varchar(255) COLLATE utf8mb4_bin PRIMARY KEY,
    `applied_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;");

echo json_encode(['status' => true, 'msg' => '✅ The database operation was completed successfully.', 'status_code' => 200], 448);
exit(0);
