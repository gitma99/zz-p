<?php
class LoggerConfig {
    public $level = 'WARNING';
    public $bufferSize = 1000;
    public $retentionDays = 30;
    public $logDir = '';
    public $telegramEnabled = true;
    public $telegramMinLevel = 'ERROR';
    public $dedupWindowSeconds = 3600;
    public $maintainerId = null;
    public $warnings = array();
    public static function fromArray(array $cfg) {
        global $LOG_DIR_PATH;
        $c = new self();
        $c->level = self::pickLevel(
            getenv('LOG_LEVEL'),
            isset($cfg['level']) ? $cfg['level'] : null,
            'WARNING', $c->warnings, 'LOG_LEVEL'
        );
        $c->bufferSize = self::pickInt(
            getenv('LOG_BUFFER_SIZE'),
            isset($cfg['buffer_size']) ? $cfg['buffer_size'] : null,
            1000, $c->warnings, 'LOG_BUFFER_SIZE', 1
        );
        $c->retentionDays = self::pickInt(
            getenv('LOG_RETENTION_DAYS'),
            isset($cfg['retention_days']) ? $cfg['retention_days'] : null,
            30, $c->warnings, 'LOG_RETENTION_DAYS', 1
        );
        $envDir = getenv('LOG_DIR');
        if ($envDir !== false && $envDir !== '') {
            $c->logDir = $envDir;
        } elseif (isset($cfg['log_dir'])) {
            $c->logDir = $cfg['log_dir'];
        } else {
            $c->logDir = ((isset($LOG_DIR_PATH) ? $LOG_DIR_PATH : '') ?: __DIR__ . '/../../logs');
        }
        $tg = isset($cfg['telegram']) ? $cfg['telegram'] : array();
        $c->telegramEnabled = self::pickBool(
            getenv('LOG_TELEGRAM_ENABLED'),
            isset($tg['enabled']) ? $tg['enabled'] : null,
            true, $c->warnings, 'LOG_TELEGRAM_ENABLED'
        );
        $c->telegramMinLevel = self::pickLevel(
            getenv('LOG_TELEGRAM_MIN_LEVEL'),
            isset($tg['min_level']) ? $tg['min_level'] : null,
            'ERROR', $c->warnings, 'LOG_TELEGRAM_MIN_LEVEL'
        );
        $c->dedupWindowSeconds = self::pickInt(
            getenv('LOG_TELEGRAM_DEDUP_SECONDS'),
            isset($tg['dedup_window_seconds']) ? $tg['dedup_window_seconds'] : null,
            3600, $c->warnings, 'LOG_TELEGRAM_DEDUP_SECONDS', 0
        );
        return $c;
    }
    private static function pickLevel($env, $file, $default, &$warnings, $name) {
        foreach (array($env, $file) as $i => $raw) {
            if ($raw === null || $raw === false || $raw === '') continue;
            $v = strtoupper(trim((string)$raw));
            if (in_array($v, array('DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'), true)) return $v;
            if ($i === 0) $warnings[] = "Logger: invalid $name='$raw', using default '$default'.";
        }
        return $default;
    }
    private static function pickInt($env, $file, $default, &$warnings, $name, $min) {
        foreach (array($env, $file) as $i => $raw) {
            if ($raw === null || $raw === false || $raw === '') continue;
            if (is_numeric($raw) && (int)$raw >= $min) return (int)$raw;
            if ($i === 0) $warnings[] = "Logger: invalid $name='$raw', using default '$default'.";
        }
        return $default;
    }
    private static function pickBool($env, $file, $default, &$warnings, $name) {
        foreach (array($env, $file) as $i => $raw) {
            if ($raw === null || $raw === false || $raw === '') continue;
            if (is_bool($raw)) return $raw;
            $v = strtolower(trim((string)$raw));
            if (in_array($v, array('1', 'true', 'yes', 'on'), true)) return true;
            if (in_array($v, array('0', 'false', 'no', 'off'), true)) return false;
            if ($i === 0) $warnings[] = "Logger: invalid $name='$raw', using default.";
        }
        return $default;
    }
}
