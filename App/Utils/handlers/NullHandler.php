<?php
require_once __DIR__ . '/../LogHandler.php';
class NullHandler implements LogHandler {
    public $seen = array();
    public function handle(array $record) {
        $this->seen[] = $record;
    }
}
