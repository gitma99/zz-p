<?php
// Pure, side-effect-free helpers. Safe to require from config.php and from
// unit tests: no database, no globals, no external HTTP, no php://input.
// These were previously defined inline in config.php; they live here so both
// the app and the unit tests use one copy.
function Conversion($byte, $one = 'GB') {
    $limit = null;
    if (isset($one)) {
        if ($one == 'GB') {
            $limit = round($byte / 1073741824, 3);
        } elseif ($one == 'MB') {
            $limit = round($byte / 1048576, 3);
        } elseif ($one == 'KB') {
            $limit = round($byte / 1024, 3);
        }
    }
    return $limit;
}

function convertToBytes($from) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $number = substr($from, 0, -2);
    $suffix = strtoupper(substr($from, -2));
    if (is_numeric(substr($suffix, 0, 1))) {
        return preg_replace('/[^\d]/', '', $from);
    }
    $exponent = array_flip($units)[$suffix] ?? null;
    if ($exponent === null) {
        return null;
    }
    return $number * (1024 ** $exponent);
}

function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

function checkInbound($inbounds, $inbound) {
    if (!is_string($inbounds) || !is_string($inbound) || $inbound === '') {
        throw new InvalidArgumentException("checkInbound: inbounds must be string and inbound must be non-empty string");
    }
    $inbounds = json_decode($inbounds, true);
    $found_inbound = false;
    if (!is_array($inbounds)) {
        return false;
    }
    foreach ($inbounds as $protocol) {
        if (!is_array($protocol)) continue;
        foreach ($protocol as $item) {
            if (strtoupper($item['tag']) == strtoupper($inbound)) {
                $found_inbound = true;
                break;
            }
        }
    }
    return $found_inbound ? true : false;
}

function membership_key($kind, $chat, $user) {
    if (!is_string($kind) || $kind === '' || empty($chat) || empty($user)) {
        throw new InvalidArgumentException("membership_key: kind, chat, and user must not be empty");
    }
    return 'apcu:' . $kind . ':' . $chat . ':' . $user;
}

function convertPersianToEnglish($string) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $response = str_replace($persian, $english, $string);
    return $response;
}

function bytes_to_int_gb($bytes) {
    if (!is_numeric($bytes)) return 0;
    return intval(floor($bytes / 1073741824)); // 1024^3
}
