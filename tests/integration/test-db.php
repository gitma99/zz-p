<?php
// tests/integration/test-db.php — execute SQL via PHP mysqli (no mysql CLI dependency).
// Reads SQL from stdin, splits on ';', executes each statement, and prints
// result rows tab-separated (one per line), mirroring `mysql --batch`.
$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MYSQL_PORT') ?: '3306');
$user = getenv('MYSQL_USER') ?: 'root';
$pass = getenv('MYSQL_PASS') ?: '';
$db   = getenv('MYSQL_DB') ?: 'zzpanel_explain_test';

$m = new mysqli($host, $user, $pass, $db, $port);
if ($m->connect_error) {
    fwrite(STDERR, 'Connect Error: ' . $m->connect_error . "\n");
    exit(1);
}

$sql = stream_get_contents(STDIN);
// ponytail: simple split on ';' — assumes no semicolons appear inside string
// values (true for all SQL run here: seeds and migrations). If that changes,
// replace with a quote-aware state machine that tracks single-quote state.
$stmts = array_filter(explode(';', $sql));
foreach ($stmts as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    $r = $m->query($stmt);
    if ($r === false) {
        fwrite(STDERR, 'Query Error: ' . $m->error . "\n" . $stmt . "\n");
        exit(1);
    }
    if ($r instanceof mysqli_result) {
        while ($row = $r->fetch_row()) {
            echo implode("\t", $row) . "\n";
        }
        $r->free();
    }
}
$m->close();
