<?php
require_once __DIR__ . '/../LogHandler.php';
class FileHandler implements LogHandler {
    private $logDir;
    private $retentionDays;
    private static $pruned = false;
    public function __construct($logDir, $retentionDays = 30) {
        $this->logDir = $logDir;
        $this->retentionDays = $retentionDays;
    }
    public function handle(array $record): void {
        if (!is_dir($this->logDir) && !@mkdir($this->logDir, 0755, true)) return;
        if (!self::$pruned) { self::$pruned = true; $this->prune(); }
        $file = $this->logDir . '/app-' . date('Y-m-d') . '.log';
        $trace = !empty($record['trace']) ? ' | trace: ' . implode(' <- ', $record['trace']) : '';
        $human = sprintf("[%s] [%s] [%s:%d] %s%s\n",
            date('Y-m-d H:i:s'), $record['level'], $record['file'],
            $record['line'], $record['msg'], $trace);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($file, $human . $json, FILE_APPEND | LOCK_EX);
    }
    private function prune() {
        foreach (glob($this->logDir . '/app-*.log') ?: array() as $f) {
            if (is_file($f) && filemtime($f) < time() - $this->retentionDays * 86400) @unlink($f);
        }
    }
}
