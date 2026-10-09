<?php
require_once __DIR__ . '/../LogHandler.php';
class TelegramHandler implements LogHandler {
    private $sender;
    private $minLevel;
    private $dedupWindowSeconds;
    private $stateFile;
    private $dedupEnabled;
    private static $stateCache = null;
    public function __construct($sender, $minLevel = 'ERROR', $dedupWindowSeconds = 3600, $stateFile = '', $dedupEnabled = true) {
        $this->sender = $sender;
        $this->minLevel = $minLevel;
        $this->dedupWindowSeconds = $dedupWindowSeconds;
        $this->dedupEnabled = $dedupEnabled;
        $this->stateFile = $stateFile === '' ? sys_get_temp_dir() . '/logger_tg_dedup.json' : $stateFile;
    }
    public static function defaultSender($maintainerId, $apiKey = null) {
        if ($apiKey === null && defined('API_KEY')) $apiKey = API_KEY;
        return function ($filePath, $fileName, $caption, $extraChatId = null) use ($maintainerId, $apiKey) {
            if (!function_exists('sendFile')) return;
            try { sendFile($maintainerId, $filePath, $fileName, 'application/json', $apiKey, $caption); } catch (Throwable $e) {}
            if ($extraChatId !== null && $extraChatId != $maintainerId) {
                try { sendFile($extraChatId, $filePath, $fileName, 'application/json', $apiKey, $caption); } catch (Throwable $e) {}
            }
        };
    }
    public function handle(array $record): void {
        $levels = array('DEBUG' => 100, 'INFO' => 200, 'WARNING' => 300, 'ERROR' => 400, 'CRITICAL' => 500);
        $recLevel = isset($levels[$record['level']]) ? $levels[$record['level']] : 0;
        $minLevel = isset($levels[$this->minLevel]) ? $levels[$this->minLevel] : 400;
        if ($recLevel < $minLevel) return;
        $key = 'global';
        if (isset($record['context']['chat_id'])) $key = (string)$record['context']['chat_id'];
        elseif (isset($record['context']['user_id'])) $key = (string)$record['context']['user_id'];
        elseif (isset($record['context']['from_id'])) $key = (string)$record['context']['from_id'];
        if (isset($record['context']['source']) && $record['context']['source'] === 'testmenu') $key = 'test:' . $key;
        $now = time();
        if ($this->dedupEnabled) {
            if (self::$stateCache === null) {
                self::$stateCache = array();
                if (is_file($this->stateFile)) {
                    $raw = @file_get_contents($this->stateFile);
                    self::$stateCache = $raw ? (json_decode($raw, true) ?: array()) : array();
                }
            }
            $state =& self::$stateCache;
            if ((isset($state[$key]) ? $state[$key] : 0) + $this->dedupWindowSeconds > $now) return;
            $state[$key] = $now;
            @file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', $record['file']);
        $name = sprintf('err-%s-%s-%s-%d.json', date('Ymd-His'), $record['level'], $safe, $record['line']);
        $path = sys_get_temp_dir() . '/' . $name;
        @file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        $hasMb = function_exists('mb_substr') && function_exists('mb_strlen');
        $lvl = $hasMb ? mb_substr($record['level'], 0, 10) : substr($record['level'], 0, 10);
        $file = $hasMb ? mb_substr($record['file'], 0, 64) : substr($record['file'], 0, 64);
        $msg = $hasMb ? mb_substr($record['msg'], 0, 200) : substr($record['msg'], 0, 200);
        $caption = sprintf('[%s] [%s:%d] %s', $lvl, $file, $record['line'], $msg);
        $len = $hasMb ? mb_strlen($caption) : strlen($caption);
        if ($len > 1024) {
            $caption = $hasMb ? mb_substr($caption, 0, 1024) : substr($caption, 0, 1024);
        }
        $sender = $this->sender;
        $extraChatId = null;
        if (isset($record['context']['source']) && $record['context']['source'] === 'testmenu' && isset($record['context']['chat_id'])) {
            $extraChatId = $record['context']['chat_id'];
        }
        try { $sender($path, $name, $caption, $extraChatId); } catch (Throwable $e) {}
        @unlink($path);
    }
}
