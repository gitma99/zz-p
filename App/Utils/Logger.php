<?php
require_once __DIR__ . '/LoggerConfig.php';
require_once __DIR__ . '/LogHandler.php';
class Logger {
    public const LEVELS = array('DEBUG' => 100, 'INFO' => 200, 'WARNING' => 300, 'ERROR' => 400, 'CRITICAL' => 500);
    private static $instance = null;
    private $buffer = array();
    private $handlers = array();
    private static $shutdownRegistered = false;
    private $cfg;
    public function __construct(LoggerConfig $cfg) { $this->cfg = $cfg; }
    public static function init(LoggerConfig $cfg) {
        $pending = self::$instance ? self::$instance->buffer : array();
        self::$instance = new self($cfg);
        foreach ($pending as $record) {
            self::$instance->buffer[] = $record;
            while (count(self::$instance->buffer) > max(1, $cfg->bufferSize)) array_shift(self::$instance->buffer);
        }
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(array('Logger', 'flush'));
        }
    }
    public static function setInstance($m) { self::$instance = $m; }
    public static function addHandler(LogHandler $h) { self::inst()->handlers[] = $h; }
    public static function info($m, array $c = array(), $include_trace = null) { self::log('INFO', $m, $c, $include_trace); }
    public static function warning($m, array $c = array(), $include_trace = null) { self::log('WARNING', $m, $c, $include_trace); }
    public static function error($m, array $c = array(), $include_trace = null) { self::log('ERROR', $m, $c, $include_trace); }
    public static function critical($m, array $c = array(), $include_trace = null) { self::log('CRITICAL', $m, $c, $include_trace); }
    public static function log($level, $message, array $context = array(), $includeTrace = null) {
        $self = self::inst();
        $name = is_int($level) ? (array_search($level, self::LEVELS, true) ?: 'INFO') : strtoupper($level);
        $value = isset(self::LEVELS[$name]) ? self::LEVELS[$name] : 200;
        $min = isset(self::LEVELS[$self->cfg->level]) ? self::LEVELS[$self->cfg->level] : 200;
        if ($value < $min) return;
        list($file, $line, $trace) = $self->caller($includeTrace !== null ? $includeTrace : ($value >= 400));
        $self->buffer[] = array('ts' => date('c'), 'level' => $name, 'file' => $file,
            'line' => $line, 'msg' => $message, 'context' => $context, 'trace' => $trace);
        while (count($self->buffer) > max(1, $self->cfg->bufferSize)) array_shift($self->buffer);
    }
    public static function flush() {
        $self = self::$instance;
        if (!$self || !$self->buffer) return;
        $batch = $self->buffer;
        $self->buffer = array();
        foreach ($batch as $record) foreach ($self->handlers as $h) $h->handle($record);
    }
    private static function inst() {
        if (!self::$instance) {
            $cfg = LoggerConfig::fromArray(array());
            self::$instance = new self($cfg);
            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function(array('Logger', 'flush'));
            }
            require_once __DIR__ . '/handlers/FileHandler.php';
            require_once __DIR__ . '/handlers/StderrHandler.php';
            self::$instance->handlers[] = new FileHandler($cfg->logDir, $cfg->retentionDays);
            self::$instance->handlers[] = new StderrHandler($cfg->level);
        }
        return self::$instance;
    }
    private function caller($wantTrace) {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        $caller = array('file' => 'unknown', 'line' => 0);
        foreach ($frames as $f) {
            if (isset($f['class']) && $f['class'] === 'Logger') continue;
            $caller = array('file' => isset($f['file']) ? basename($f['file']) : 'unknown', 'line' => isset($f['line']) ? $f['line'] : 0);
            break;
        }
        $trace = null;
        if ($wantTrace) {
            $trace = array();
            foreach (array_slice($frames, 1, 10) as $i => $f) {
                $fn = (isset($f['class']) ? $f['class'] : '') . (isset($f['type']) ? $f['type'] : '') . (isset($f['function']) ? $f['function'] : '{main}');
                $loc = isset($f['file']) ? basename($f['file']) . '(' . (isset($f['line']) ? $f['line'] : 0) . ')' : '{main}';
                $trace[] = "#$i $loc: $fn(...)";
            }
        }
        return array($caller['file'], $caller['line'], $trace);
    }
}
