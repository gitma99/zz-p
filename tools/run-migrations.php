<?php
// tools/run-migrations.php — apply versioned schema migrations
//
// Reads every *.sql file in sql/migrations/ in sorted order, skips any
// already recorded in schema_migrations, executes the rest, and records
// each one. Idempotent: safe to run on every boot, install, and update.
//
// Env vars: DB_HOST DB_USER DB_PASS DB_NAME
set_time_limit(0);
error_reporting(E_ALL ^ E_NOTICE);

$host = getenv('DB_HOST') ?: 'localhost';
$port = (int)(getenv('DB_PORT') ?: 3306);
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$name = getenv('DB_NAME');

if (!$user || !$name) {
    fwrite(STDERR, "ERROR: DB_USER and DB_NAME must be set\n");
    exit(1);
}

$mysqli = new mysqli($host, $user, $pass, $name, $port);
if ($mysqli->connect_error) {
    fwrite(STDERR, "ERROR: " . $mysqli->connect_error . "\n");
    exit(1);
}

// Ensure the tracker table exists (fresh installs already get it from
// sql/sql.php, but this keeps the runner self-contained).
$mysqli->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `filename` varchar(255) COLLATE utf8mb4_bin PRIMARY KEY,
    `applied_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;");

$migration_dir = __DIR__ . '/../sql/migrations';
if (!is_dir($migration_dir)) {
    echo "No migrations directory at $migration_dir — nothing to apply.\n";
    $mysqli->close();
    exit(0);
}

// Which migrations have already been applied?
$applied = [];
$res = $mysqli->query("SELECT `filename` FROM `schema_migrations`");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $applied[] = $row['filename'];
    }
    $res->free();
}

// Migration files in sorted (chronological) order.
$files = [];
foreach (scandir($migration_dir) as $f) {
    if (substr($f, -4) === '.sql') {
        $files[] = $f;
    }
}
sort($files);

$applied_count = 0;
foreach ($files as $file) {
    if (in_array($file, $applied, true)) {
        continue;
    }

    $path = $migration_dir . '/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "ERROR: cannot read $path\n");
        exit(1);
    }

    echo "Applying migration: $file\n";
    if (!$mysqli->multi_query($sql)) {
        fwrite(STDERR, "ERROR applying $file: " . $mysqli->error . "\n");
        exit(1);
    }

    // Consume all result sets so the connection stays usable.
    while ($mysqli->more_results() && $mysqli->next_result()) {
        if ($r = $mysqli->store_result()) {
            $r->free();
        }
    }

    // multi_query() only reports the first statement's success; a failing
    // ALTER later in the file surfaces here after all results are drained.
    if ($mysqli->errno) {
        fwrite(STDERR, "ERROR applying $file: " . $mysqli->error . "\n");
        exit(1);
    }

    $escaped = $mysqli->real_escape_string($file);
    $mysqli->query(
        "INSERT INTO `schema_migrations` (`filename`, `applied_at`) " .
        "VALUES ('$escaped', NOW())"
    );
    $applied_count++;
}

if ($applied_count === 0) {
    echo "No new migrations to apply.\n";
} else {
    echo "Applied $applied_count migration(s).\n";
}

$mysqli->close();
exit(0);
