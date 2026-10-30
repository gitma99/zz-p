<?php
// Unit test: db_row() and db_exec() — defined in App/Utils/db_tools.php.
// Self-contained: stubs mysqli so no database connection is needed.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/db_tools.php';
error_reporting(E_ALL ^ E_NOTICE);

// --- Stubs ---

class StubResult {
    public $rows;
    public $index = 0;
    public function __construct($rows) { $this->rows = $rows; }
    public function fetch_assoc() {
        if (isset($this->rows[$this->index])) {
            return $this->rows[$this->index++];
        }
        return null;
    }
}

class StubStmt {
    public $exec_result;
    public $get_result_val;
    public $closed = false;
    public $bind_param_called = false;
    public function bind_param($types, ...$params) {
        $this->bind_param_called = true;
        if (count($params) === 0) {
            trigger_error("Wrong parameter count for mysqli_stmt::bind_param()", E_USER_WARNING);
        }
    }
    public function execute() { return $this->exec_result; }
    public function get_result() { return $this->get_result_val; }
    public function close() { $this->closed = true; }
}

class StubMysqli {
    public $prepare_result;
    public $prepare_called_with;
    public function prepare($query) {
        $this->prepare_called_with = $query;
        return $this->prepare_result;
    }
}

// --- Helpers ---

function stmt($exec_result = true, $get_result_val = null) {
    $s = new StubStmt();
    $s->exec_result = $exec_result;
    $s->get_result_val = $get_result_val;
    return $s;
}

function mysqli($prepare_result) {
    $m = new StubMysqli();
    $m->prepare_result = $prepare_result;
    return $m;
}

// --- db_row tests ---

$sql = mysqli(stmt(true, new StubResult([['id' => 1, 'name' => 'a']])));
assert_eq(['id' => 1, 'name' => 'a'], db_row($sql, 'SELECT * FROM t WHERE id = ?', 's', [1]), 'db_row returns row');
assert_eq('SELECT * FROM t WHERE id = ?', $sql->prepare_called_with, 'db_row passes query to prepare');
assert_true($sql->prepare_result->closed, 'db_row closes statement');

$sql = mysqli(null);
assert_eq(null, db_row($sql, 'SELECT ?', 's', [1]), 'db_row prepare fails -> null');

$s = stmt(false);
$sql = mysqli($s);
assert_eq(null, db_row($sql, 'SELECT ?', 's', [1]), 'db_row execute fails -> null');
assert_true($s->closed, 'db_row closes stmt on execute failure');

$s = stmt(true, new StubResult([]));
$sql = mysqli($s);
assert_eq(null, db_row($sql, 'SELECT ?', 's', [1]), 'db_row empty result -> null');

$s = new StubStmt();
$s->exec_result = true;
$s->get_result_val = false;
$sql = mysqli($s);
assert_eq(null, db_row($sql, 'SELECT ?', 's', [1]), 'db_row get_result false -> null');
assert_true($s->closed, 'db_row closes stmt when get_result false');

// Parameterless db_row
$s = stmt(true, new StubResult([['cnt' => 42]]));
$sql = mysqli($s);
assert_eq(['cnt' => 42], db_row($sql, 'SELECT COUNT(*) FROM t'), 'db_row parameterless');
assert_true(!$s->bind_param_called, 'db_row parameterless does not call bind_param');
assert_true($s->closed, 'db_row closes statement on parameterless success');

// InvalidArgumentException tests on db_row
assert_throws(function() use ($sql) {
    db_row($sql, 'SELECT * FROM t WHERE id = ?', 'i', []);
}, InvalidArgumentException::class, 'db_row throws when query has ? but params empty');

assert_throws(function() use ($sql) {
    db_row($sql, 'SELECT * FROM t WHERE id = ? AND status = ?', 'is', [1]);
}, InvalidArgumentException::class, 'db_row throws when 2 ? but 1 param');

assert_throws(function() use ($sql) {
    db_row($sql, 'SELECT * FROM t WHERE id = ?', 'ii', [1, 2]);
}, InvalidArgumentException::class, 'db_row throws when 1 ? but 2 params');

// --- db_exec tests ---

$sql = mysqli(stmt(true));
assert_eq(true, db_exec($sql, 'UPDATE t SET x = ?', 's', [1]), 'db_exec returns true');
assert_true($sql->prepare_result->closed, 'db_exec closes statement');

$sql = mysqli(null);
assert_eq(false, db_exec($sql, 'UPDATE t SET x = ?', 's', [1]), 'db_exec prepare fails -> false');

$s = stmt(false);
$sql = mysqli($s);
assert_eq(false, db_exec($sql, 'UPDATE t SET x = ?', 's', [1]), 'db_exec execute fails -> false');
assert_true($s->closed, 'db_exec closes stmt on execute failure');

// Parameterless db_exec
$s = stmt(true);
$sql = mysqli($s);
assert_eq(true, db_exec($sql, 'TRUNCATE TABLE t'), 'db_exec parameterless');
assert_true(!$s->bind_param_called, 'db_exec parameterless does not call bind_param');
assert_true($s->closed, 'db_exec closes statement on parameterless success');

// InvalidArgumentException tests on db_exec
assert_throws(function() use ($sql) {
    db_exec($sql, 'UPDATE t SET x = ?', 'i', []);
}, InvalidArgumentException::class, 'db_exec throws when query has ? but params empty');

assert_throws(function() use ($sql) {
    db_exec($sql, 'UPDATE t SET x = ? WHERE id = ?', 'ii', [1]);
}, InvalidArgumentException::class, 'db_exec throws when 2 ? but 1 param');

assert_throws(function() use ($sql) {
    db_exec($sql, 'UPDATE t SET x = ?', 'ii', [1, 2]);
}, InvalidArgumentException::class, 'db_exec throws when 1 ? but 2 params');

echo "OK db_tools_test.php\n";
exit(0);
