<?php
class LoggerConfig {
    public $level = 'INFO';
    public $bufferSize = 1000;
    public $retentionDays = 30;
    public $logDir = '';
    public $telegramEnabled = true;
    public $telegramMinLevel = 'ERROR';
    public $dedupWindowSeconds = 3600;
    public $maintainerId = null;
    public static function fromArray(array $cfg) {
        global $LOG_DIR_PATH;
        $c = new self();
        $c->level = strtoupper(isset($cfg['level']) ? $cfg['level'] : (getenv('LOG_LEVEL') ?: 'INFO'));
        $c->bufferSize = (int)(isset($cfg['buffer_size']) ? $cfg['buffer_size'] : (getenv('LOG_BUFFER_SIZE') ?: 1000));
        $c->retentionDays = (int)(isset($cfg['retention_days']) ? $cfg['retention_days'] : 30);
        $c->logDir = isset($cfg['log_dir']) ? $cfg['log_dir'] : ((isset($LOG_DIR_PATH) ? $LOG_DIR_PATH : '') ?: __DIR__ . '/../../logs');
        $tg = isset($cfg['telegram']) ? $cfg['telegram'] : array();
        $c->telegramEnabled = isset($tg['enabled']) ? $tg['enabled'] : true;
        $c->telegramMinLevel = strtoupper(isset($tg['min_level']) ? $tg['min_level'] : 'ERROR');
        $c->dedupWindowSeconds = (int)(isset($tg['dedup_window_seconds']) ? $tg['dedup_window_seconds'] : 3600);
        return $c;
    }
}
