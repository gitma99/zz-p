<?php
require_once __DIR__ . '/../LogHandler.php';
class StderrHandler implements LogHandler {
    private $minLevel;
    private $handle = null;
    public function __construct($minLevel = 'WARNING') {
        $this->minLevel = strtoupper((string)$minLevel);
    }
    public function handle(array $record): void {
        $levels = array('DEBUG' => 100, 'INFO' => 200, 'WARNING' => 300, 'ERROR' => 400, 'CRITICAL' => 500);
        $rec = isset($levels[$record['level']]) ? $levels[$record['level']] : 200;
        $min = isset($levels[$this->minLevel]) ? $levels[$this->minLevel] : 300;
        if ($rec < $min) return;
        try {
            if ($this->handle === null) {
                $this->handle = @fopen('php://stderr', 'w');
                if ($this->handle === false) { $this->handle = null; return; }
            }
            $trace = !empty($record['trace']) ? ' | trace: ' . implode(' <- ', $record['trace']) : '';
            $line = sprintf("[%s] [%s] [%s:%d] %s%s\n",
                date('Y-m-d H:i:s'), $record['level'], $record['file'],
                $record['line'], $record['msg'], $trace);
            @fwrite($this->handle, $line);
        } catch (Throwable $e) {}
    }
}
