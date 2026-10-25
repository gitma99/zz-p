<?php

// sql/sql.php — thin compatibility shim.
//
// All schema creation now lives in versioned migration files under
// sql/migrations/ and is applied by tools/run-migrations.php on every boot,
// install, and update. This file no longer creates tables or indexes — it
// only ensures the schema_migrations tracker table exists (defensive; the
// runner also creates it) and returns a JSON status so the HTTP update path
// in update.sh can check it.

if (!isset($_GET['db_username'], $_GET['db_password'], $_GET['db_name'])) {
    die(json_encode(['status' => false, 'msg' => 'Database operation failed -> missing credentials', 'error_code' => 401], 448));
}

$db_host = getenv('DB_HOST') ?: 'localhost';
$sql = new mysqli($db_host, $_GET['db_username'], $_GET['db_password'], $_GET['db_name']);
$sql->set_charset("utf8mb4");

// Defensive: ensure the migration tracker table exists even if this file is
// run without the migration runner. The runner applies all versioned
// migrations (init + indexes) right after this on every boot/install/update.
$sql->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `filename` varchar(255) COLLATE utf8mb4_bin PRIMARY KEY,
    `applied_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;");

if ($sql->connect_error) {
    echo json_encode(['status' => false, 'msg' => '❌ The connection with the database encountered an error : ' . $sql->connect_error, 'status_code' => 401], 448);
} else {
    echo json_encode(['status' => true, 'msg' => '✅ The database operation was completed successfully.', 'status_code' => 200], 448);
}
