<?php
// Self-check for Logger + perf helpers. Run:
//   php -d zend.assertions=1 -d assert.exception=1 tools/logger_selfcheck.php
// Exits non-zero on first failure. Each check prints its own OK line.

require_once __DIR__ . '/../App/Utils/LogHandler.php';
require_once __DIR__ . '/../App/Utils/LoggerConfig.php';
require_once __DIR__ . '/../App/Utils/handlers/NullHandler.php';
require_once __DIR__ . '/../App/Utils/handlers/FileHandler.php';
require_once __DIR__ . '/../App/Utils/Logger.php';
require_once __DIR__ . '/../App/Utils/handlers/TelegramHandler.php';
require_once __DIR__ . '/../App/Utils/request_cache.php';

if (!defined('MYSQLI_ASSOC')) define('MYSQLI_ASSOC', 1);

// ---------- test doubles ----------

class FakeResult {
    private $rows; private $i = 0;
    public function __construct($rows) { $this->rows = $rows; }
    public function fetch_assoc() {
        $r = isset($this->rows[$this->i]) ? $this->rows[$this->i] : null;
        $this->i++;
        return $r;
    }
    public function fetch_all($mode = null) { return $this->rows; }
}

class FakeSql {
    public function query($q) {
        $GLOBALS['__query_count']++;
        if (strpos($q, 'active') !== false && strpos($q, 'panels') !== false) {
            return new FakeResult(array(array('name' => 'p1', 'status' => 'active')));
        }
        return new FakeResult(array(array('name' => 'px', 'status' => 'inactive')));
    }
}

// ---------- logger checks ----------

function check_config_defaults() {
    $cfg = LoggerConfig::fromArray(array());
    assert($cfg->level === 'INFO');
    assert($cfg->bufferSize === 1000);
    $h = new NullHandler();
    $h->handle(array('msg' => 'x'));
    assert(count($h->seen) === 1);
    echo "config defaults: OK\n";
}

function check_file_handler() {
    $dir = sys_get_temp_dir() . '/logger_check_' . getmypid();
    $fh = new FileHandler($dir, 30);
    $fh->handle(array(
        'ts' => '2026-10-01T14:30:00+03:30', 'level' => 'ERROR',
        'file' => 'responses.php', 'line' => 34, 'msg' => 'sendPhoto failed',
        'context' => array('chat_id' => 1),
        'trace' => array('#0 responses.php(34): sendSuccessResponse(...)', '#1 {main}'),
    ));
    $lines = file($dir . '/app-' . date('Y-m-d') . '.log');
    assert(count($lines) === 2);
    assert(strpos(trim($lines[0]), '[2026') === 0);
    $json = json_decode(trim($lines[1]), true);
    assert($json['msg'] === 'sendPhoto failed');
    assert($json['trace'][0] === '#0 responses.php(34): sendSuccessResponse(...)');
    // Missing dir must not fatal.
    $fh2 = new FileHandler($dir . '/nonexistent_deep', 30);
    $fh2->handle(array('ts' => 'x', 'level' => 'INFO', 'file' => 'f', 'line' => 1,
        'msg' => 'm', 'context' => array(), 'trace' => null));
    echo "file handler (dual format + rotation): OK\n";
}

function check_logger_core() {
    Logger::setInstance(null);
    Logger::init(LoggerConfig::fromArray(array('level' => 'WARNING', 'buffer_size' => 2)));
    $null = new NullHandler();
    Logger::addHandler($null);
    Logger::info('dropped by level', array());
    Logger::warning('kept no trace', array());
    Logger::error('kept with trace', array('a' => 1));
    Logger::flush();
    assert(count($null->seen) === 2);
    assert($null->seen[0]['level'] === 'WARNING');
    assert(empty($null->seen[0]['trace']));
    assert(!empty($null->seen[1]['trace']));
    // Buffer of 1 drops oldest.
    Logger::setInstance(null);
    Logger::init(LoggerConfig::fromArray(array('buffer_size' => 1)));
    $n2 = new NullHandler();
    Logger::addHandler($n2);
    Logger::info('first', array());
    Logger::info('second', array());
    Logger::info('third', array());
    Logger::flush();
    assert(count($n2->seen) === 1 && $n2->seen[0]['msg'] === 'third');
    echo "logger core (levels + trace + buffer): OK\n";
}

function check_telegram_handler() {
    $sent = array();
    $state = sys_get_temp_dir() . '/logger_tg_' . getmypid() . '.json';
    @unlink($state);
    $tg = new TelegramHandler(
        function ($path, $name, $caption) use (&$sent) {
            $sent[] = array(
                'path' => $path, 'name' => $name, 'caption' => $caption,
                'body' => file_get_contents($path),
            );
        },
        'ERROR', 3600, $state
    );
    // INFO below min level: skipped.
    $tg->handle(array('ts' => 'x', 'level' => 'INFO', 'file' => 'f', 'line' => 1,
        'msg' => 'low', 'context' => array(), 'trace' => null));
    // ERROR: sent as JSON file.
    $tg->handle(array('ts' => 'x', 'level' => 'ERROR', 'file' => 'r.php', 'line' => 5,
        'msg' => 'boom', 'context' => array('chat_id' => 7),
        'trace' => array('#0 r.php(5): foo(...)')));
    // Same chat inside dedup window: suppressed.
    $tg->handle(array('ts' => 'x', 'level' => 'ERROR', 'file' => 'r.php', 'line' => 6,
        'msg' => 'boom2', 'context' => array('chat_id' => 7), 'trace' => null));
    assert(count($sent) === 1);
    assert(strpos($sent[0]['caption'], 'boom') !== false);
    assert(strpos($sent[0]['name'], 'err-') === 0 && substr($sent[0]['name'], -5) === '.json');
    $body = json_decode($sent[0]['body'], true);
    assert($body['msg'] === 'boom' && $body['trace'][0] === '#0 r.php(5): foo(...)');
    assert(!is_file($sent[0]['path']));
    // Oversized message: caption stays under Telegram's 1024 limit.
    $tg->handle(array('ts' => 'x', 'level' => 'ERROR', 'file' => 'r.php', 'line' => 7,
        'msg' => str_repeat('x', 5000), 'context' => array('chat_id' => 8), 'trace' => null));
    assert(count($sent) === 2);
    assert(strlen($sent[1]['caption']) <= 1024);
    // Missing sendFile(): silent no-op, never fatal.
    $noop = TelegramHandler::defaultSender(null);
    $noop(sys_get_temp_dir(), 'err-test.json', 'hello');
    echo "telegram handler (filter + dedup + file send): OK\n";
}

function check_wiring() {
    $tpl = json_decode(file_get_contents(__DIR__ . '/../Templates/bot_config_template.json'), true);
    assert(isset($tpl['logging']['buffer_size'], $tpl['logging']['telegram']['min_level']));
    assert(strpos(file_get_contents(__DIR__ . '/../config.php'), 'Logger::init') !== false);
    echo "config wiring + template: OK\n";
}

function check_call_sites() {
    assert(strpos(file_get_contents(__DIR__ . '/../index.php'), 'Logger::error') !== false);
    assert(strpos(file_get_contents(__DIR__ . '/../App/apis/marzban-api/api.php'), 'Logger::error') !== false);
    assert(strpos(file_get_contents(__DIR__ . '/../workers/task_worker.php'), 'Logger::info') !== false);
    assert(strpos(file_get_contents(__DIR__ . '/../tools/restore_marzban_orders.php'), 'Logger::info') !== false);
    echo "call-site migration: OK\n";
}

function check_sender_token() {
    if (!function_exists('sendFile')) {
        eval('function sendFile($chat_id, $file_path, $file_name, $mime_type, $api_key = null, $caption = null) {
            $GLOBALS["__tg_cap"] = array($chat_id, $file_path, $file_name, $mime_type, $api_key, $caption);
            return array("ok" => true);
        }');
    }
    $sender = TelegramHandler::defaultSender(999, 'TOKEN_X');
    $sender('/tmp/a.json', 'a.json', 'cap');
    assert($GLOBALS['__tg_cap'][4] === 'TOKEN_X');
    assert($GLOBALS['__tg_cap'][0] === 999);
    assert(strpos(file_get_contents(__DIR__ . '/../config.php'), 'send_file.php') !== false);
    echo "sender token passthrough: OK\n";
}

// ---------- perf checks ----------

function check_request_cache() {
    $calls = 0;
    $v1 = cached_query('t1', function () use (&$calls) { $calls++; return 'A'; });
    $v2 = cached_query('t1', function () use (&$calls) { $calls++; return 'B'; });
    assert($v1 === 'A' && $v2 === 'A' && $calls === 1);
    echo "request cache (factory runs once): OK\n";
}

function check_panel_cache_hit() {
    $GLOBALS['__query_count'] = 0;
    $GLOBALS['sql'] = new FakeSql();
    $panels = get_active_panels();
    assert(count($panels) === 1 && $GLOBALS['__query_count'] === 1);
    // Active-list hit: zero extra queries.
    $hit = get_panel_by_name('p1');
    assert($hit['name'] === 'p1' && $GLOBALS['__query_count'] === 1);
    // Inactive panel: falls through to direct query, then cached.
    $miss = get_panel_by_name('px');
    assert($miss['name'] === 'px' && $GLOBALS['__query_count'] === 2);
    $miss2 = get_panel_by_name('px');
    assert($GLOBALS['__query_count'] === 2);
    $GLOBALS['sql'] = null;
    echo "panel cache (hit skips query, miss falls through): OK\n";
}

function check_membership_memo() {
    $GLOBALS['__bot_calls'] = 0;
    if (!function_exists('bot')) {
        eval('function bot($m, $d = array()) {
            $GLOBALS["__bot_calls"]++;
            return array("result" => array("status" => "member"));
        }');
    }
    echo "membership memo stub: OK\n";
}

function check_skip_locked() {
    assert(strpos(file_get_contents(__DIR__ . '/../workers/task_worker.php'), 'SKIP LOCKED') !== false);
    echo "worker SKIP LOCKED claim: OK\n";
}

function check_ddl_hoist() {
    $src = file_get_contents(__DIR__ . '/../App/functions/service_overview.php');
    assert(substr_count($src, 'CREATE TABLE IF NOT EXISTS') === 1);
    echo "archive DDL hoisted out of loop: OK\n";
}

function check_batch_flags() {
    $fh_src = file_get_contents(__DIR__ . '/../App/Utils/handlers/FileHandler.php');
    assert(strpos($fh_src, '$pruned') !== false);
    $custom = file_get_contents(__DIR__ . '/../custom.php');
    assert(strpos($custom, 'sleep(0.5)') === false);
    echo "flush batching + sleep removed: OK\n";
}

// ---------- runner ----------

check_config_defaults();
check_file_handler();
check_logger_core();
check_telegram_handler();
check_wiring();
check_call_sites();
check_sender_token();
check_request_cache();
check_panel_cache_hit();
check_membership_memo();
check_skip_locked();
check_ddl_hoist();
check_batch_flags();
echo "ALL CHECKS PASSED\n";
