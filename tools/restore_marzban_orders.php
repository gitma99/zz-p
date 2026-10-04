#!/usr/bin/env php
<?php
// tools/restore_marzban_orders.php
// Usage examples:
// php tools/restore_marzban_orders.php --panel=panel_name [--limit=500 --offset=0] [--insert] [--verbose] [--log-file=path]

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../App/apis/marzban-api/api.php';

$options = getopt('', ['panel:', 'all', 'limit::', 'offset::', 'insert', 'verbose', 'log-file:', 'report-csv:', 'batch-size::', 'skip-disabled', 'force']);

$panelName = $options['panel'] ?? null;
$all = isset($options['all']);
// treat bare flags like --limit (no value) as NULL unless a numeric value provided
$limit = null;
if (array_key_exists('limit', $options) && $options['limit'] !== false && is_numeric($options['limit'])) {
    $limit = intval($options['limit']);
}
$offset = null;
if (array_key_exists('offset', $options) && $options['offset'] !== false && is_numeric($options['offset'])) {
    $offset = intval($options['offset']);
}
$doInsert = isset($options['insert']);
$verbose = isset($options['verbose']);
$logFile = $options['log-file'] ?? null;
$batchSize = isset($options['batch-size']) ? intval($options['batch-size']) : ($limit ?? 1000);
$skipDisabled = isset($options['skip-disabled']);
// If skip-disabled is set, ask API only for active users
$status_filter = $skipDisabled ? 'active' : null;
$force = isset($options['force']);

// prepare logs dir and per-run files (timestamped and sortable)
$logs_dir = __DIR__ . '/../logs';
if (!is_dir($logs_dir)) {
    if (!mkdir($logs_dir, 0755, true)) {
        echo "Could not create logs directory: $logs_dir\n";
        exit(1);
    }
}
$run_id = date('YmdHis');
$logFile = $options['log-file'] ?? "{$logs_dir}/restore_{$run_id}.log";
$logHandle = fopen($logFile, 'a');
if (!$logHandle) {
    echo "Could not open log file: $logFile\n";
    exit(1);
}

// CSV report file (per run by default)
$csvFile = $options['report-csv'] ?? "{$logs_dir}/restore_{$run_id}.csv";
$csvHandle = fopen($csvFile, 'a');
if (!$csvHandle) {
    // we have an open log file, so use it
    logline("Could not open CSV file: $csvFile");
    $csvHandle = false;
} else {
    // write header if file is empty
    if (ftell($csvHandle) === 0) {
        fputcsv($csvHandle, ['panel','username','code','from_id','volume_gb','status','link','action','message']);
    }
}

// log run start
logline("Run started: id={$run_id}, log={$logFile}, csv={$csvFile}");

function logline($s)
{
    global $logHandle;
    Logger::info($s);
    $time = date('Y-m-d H:i:s');
    $line = "[$time] $s\n";
    echo $line;
    if ($logHandle) fwrite($logHandle, $line);
}

// helper: safe int GB
function bytes_to_int_gb($bytes)
{
    if (!is_numeric($bytes)) return 0;
    return intval(floor($bytes / 1073741824)); // 1024^3
}

// helper: append row to CSV report if available
function csv_write($row)
{
    global $csvHandle;
    if ($csvHandle && is_resource($csvHandle)) {
        fputcsv($csvHandle, $row);
    }
}

// find panels to operate
$panels = [];
if ($all) {
    $res = $sql->query("SELECT * FROM `panels` WHERE `type` = 'marzban' AND `status` = 'active'");
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) $panels[] = $row;
    }
    if (count($panels) === 0) {
        // fallback: any panel with login_link containing "/api/" or just all panels
        $res2 = $sql->query("SELECT * FROM `panels` WHERE `status` = 'active'");
        while ($row = $res2->fetch_assoc()) $panels[] = $row;
    }
} else if ($panelName) {
    $panelRow = $sql->query("SELECT * FROM `panels` WHERE `name` = '" . $sql->real_escape_string($panelName) . "'")->fetch_assoc();
    if ($panelRow) $panels[] = $panelRow;
    else {
        logline("Panel not found: $panelName");
        exit(1);
    }
} else {
    echo "Usage: php tools/restore_marzban_orders.php --panel=panel_name | --all [--limit=N] [--offset=N] [--insert] [--verbose] [--log-file=path]\n";
    exit(1);
}

$stats = [
    'checked' => 0,
    'skipped_exists' => 0,
    'inserted' => 0,
    'errors' => 0,
    'malformed' => 0,
    'conflicts' => 0,
];

foreach ($panels as $panel) {
    $panel_name = $panel['name'];
    $panel_url = $panel['login_link'];
    logline("Processing panel: $panel_name  (login_link={$panel_url})");

    $token = false;
    try {
        $token = get_marzban_panel_token($panel_name);
    } catch (Throwable $e) {
        logline("Error while getting token for panel $panel_name: " . $e->getMessage());
        continue;
    }
    if (!$token) {
        logline("Could not obtain token for panel $panel_name. Skipping.");
        continue;
    }

    $local_offset = $offset;
    $local_limit = $limit;

    while (true) {
        // call getUsersInfo
        try {
            $api_response = getUsersInfo($token, $panel_url, $local_offset, $local_limit, $status_filter, null);
            $resp = $api_response['data'] ?? null;
            $err = $api_response['error'] ?? false;
        } catch (Throwable $e) {
            logline("getUsersInfo exception for panel $panel_name: " . $e->getMessage());
            $stats['errors']++;
            break;
        }

        if ($err !== false) {
            logline("API error for panel $panel_name: $err");
            $stats['errors']++;
            break;
        }

        if (!isset($resp['users']) || !is_array($resp['users']) || count($resp['users']) === 0) {
            logline("No more users returned for panel $panel_name (offset=$local_offset, limit=$local_limit).\n");
            break;
        }

        foreach ($resp['users'] as $user) {
            $stats['checked']++;
            $username = $user['username'] ?? null;
            if (!$username) {
                $stats['malformed']++;
                logline("Malformed user entry (no username) in panel $panel_name");
                csv_write([$panel_name, '', '', '', '', '', '', 'malformed', 'no username']);
                continue;
            }

            $pos = strrpos($username, '_');
            if ($pos === false) {
                // skip and report usernames without separator
                $stats['malformed']++;
                logline("Malformed username (no last underscore): $username");
                csv_write([$panel_name, $username, '', '', '', '', '', 'malformed', 'no underscore']);
                continue;
            }

            $code = trim(substr($username, 0, $pos));
            $from_id = trim(substr($username, $pos + 1));

            // code must not be empty
            if ($code === '') {
                $stats['malformed']++;
                logline("Malformed username (empty code before underscore): $username");
                csv_write([$panel_name, $username, $code, $from_id, '', '', '', 'malformed', 'empty code']);
                continue;
            }

            if (!ctype_digit($from_id)) {
                // from_id must be numeric; skip and report
                $stats['malformed']++;
                logline("Malformed username (from_id not numeric): $username -> from_id=$from_id");
                csv_write([$panel_name, $username, $code, $from_id, '', '', '', 'malformed', 'from_id not numeric']);
                continue;
            }

            if (strlen($code) > 20) {
                $stats['malformed']++;
                logline("Code too long (>20) for username $username -> code: $code");
                csv_write([$panel_name, $username, $code, $from_id, '', '', '', 'malformed', 'code too long']);
                continue;
            }

            // check exact match
            $stmt = $sql->prepare("SELECT `row`, `from_id`, `location`, `status` FROM `orders` WHERE `code` = ? AND `from_id` = ? AND `location` = ? LIMIT 1");
            if (!$stmt) {
                logline("DB prepare error: " . $sql->error);
                $stats['errors']++;
                continue;
            }
            $stmt->bind_param('sss', $code, $from_id, $panel_name);
            $stmt->execute();
            $res = $stmt->get_result();
            $existingRow = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
            if ($existingRow) {
                $stats['skipped_exists']++;
                if ($verbose) logline("Exists: code=$code, from_id=$from_id, location=$panel_name");
                csv_write([$panel_name, $username, $code, $from_id, '', $existingRow['status'] ?? '', '', 'exists', 'exists in DB row=' . ($existingRow['row'] ?? '')]);
                $stmt->close();
                continue;
            }
            $stmt->close();

            // check conflicts: same code & location but different from_id OR existing code globally
            $stmt2 = $sql->prepare("SELECT `row`, `from_id`, `location` FROM `orders` WHERE `code` = ? AND `location` = ? LIMIT 1");
            if (!$stmt2) {
                logline("DB prepare error (conflict check): " . $sql->error);
                $stats['errors']++;
                continue;
            }
            $stmt2->bind_param('ss', $code, $panel_name);
            $stmt2->execute();
            $r2 = $stmt2->get_result();
            if ($r2 && $r2->num_rows > 0) {
                $found = $r2->fetch_assoc();
                if ($found['from_id'] != $from_id) {
                    $stats['conflicts']++;
                    $msg = "Conflict: code=$code exists in orders with from_id={$found['from_id']} (panel={$found['location']}) but panel reports from_id=$from_id. Skipping (report only).";
                    logline($msg . "\n");
                    csv_write([$panel_name, $username, $code, $from_id, '', '', '', 'conflict', $msg]);
                    $stmt2->close();
                    continue; // skip insertion by default
                }
            }
            $stmt2->close();

            // prepare insertion payload
            $volume_gb = bytes_to_int_gb($user['data_limit'] ?? 0);
            $link = '';
            if (isset($user['links']) && is_array($user['links']) && count($user['links']) > 0) $link = $user['links'][0];
            $status_val = (isset($user['status']) && $user['status'] === 'active') ? 'active' : 'inactive';
            $date_val = '30';
            $price_val = '0';
            $protocol_val = null; // NULL
            $type_val = 'marzban';

            $proposal = [
                'from_id' => $from_id,
                'location' => $panel_name,
                'protocol' => $protocol_val,
                'date' => $date_val,
                'volume' => (string)$volume_gb,
                'link' => $link,
                'price' => $price_val,
                'code' => $code,
                'status' => $status_val,
                'type' => $type_val
            ];

            // report / dry-run
            logline("Missing -> Proposal: code={$proposal['code']}, from_id={$proposal['from_id']}, location={$proposal['location']}, volume={$proposal['volume']}GB, status={$proposal['status']}" );
            csv_write([$panel_name, $username, $proposal['code'], $proposal['from_id'], $proposal['volume'], $proposal['status'], $proposal['link'], 'proposal', 'missing locally']);

            if (!$doInsert) continue;

            // perform insert
            // handle protocol NULL explicitly by using NULL in SQL when needed
            if (is_null($proposal['protocol'])) {
                $ins = $sql->prepare("INSERT INTO `orders` (`from_id`,`location`,`protocol`,`date`,`volume`,`link`,`price`,`code`,`status`,`type`) VALUES (?,?,NULL,?,?,?,?,?,?,?)");
                if (!$ins) {
                    logline("DB prepare (insert) error: " . $sql->error);
                    $stats['errors']++;
                    continue;
                }
                $ins->bind_param('sssssssss', $proposal['from_id'], $proposal['location'], $proposal['date'], $proposal['volume'], $proposal['link'], $proposal['price'], $proposal['code'], $proposal['status'], $proposal['type']);
            } else {
                $ins = $sql->prepare("INSERT INTO `orders` (`from_id`,`location`,`protocol`,`date`,`volume`,`link`,`price`,`code`,`status`,`type`) VALUES (?,?,?,?,?,?,?,?,?,?)");
                if (!$ins) {
                    logline("DB prepare (insert) error: " . $sql->error);
                    $stats['errors']++;
                    continue;
                }
                $ins->bind_param('ssssssssss', $proposal['from_id'], $proposal['location'], $proposal['protocol'], $proposal['date'], $proposal['volume'], $proposal['link'], $proposal['price'], $proposal['code'], $proposal['status'], $proposal['type']);
            }
            if ($ins->execute()) {
                $stats['inserted']++;
                $insertId = $sql->insert_id;
                logline("Inserted: code={$proposal['code']}, from_id={$proposal['from_id']}, rowid=" . $insertId);
                csv_write([$panel_name, $username, $proposal['code'], $proposal['from_id'], $proposal['volume'], $proposal['status'], $proposal['link'], 'inserted', 'row=' . $insertId]);
            } else {
                $stats['errors']++;
                $errMsg = $ins->error;
                logline("Insert failed for code={$proposal['code']}: " . $errMsg);
                csv_write([$panel_name, $username, $proposal['code'], $proposal['from_id'], $proposal['volume'], $proposal['status'], $proposal['link'], 'insert_failed', $errMsg]);
            }
            $ins->close();
        }

        // paging: determine continuation
        $countReturned = count($resp['users']);

        // If both limit and offset are null, we only requested a single page — stop after first page
        if (is_null($local_limit) && is_null($local_offset)) {
            break;
        }

        // Ensure offset is numeric and advance
        $local_offset = (is_null($local_offset) ? 0 : $local_offset) + $countReturned;

        // If we used a limit and received fewer results than the limit, we're done
        if (!is_null($local_limit) && $countReturned < $local_limit) break;

        // small sleep to be gentle with API
        sleep(1);
    }

    logline("Panel $panel_name done. stats so far: checked={$stats['checked']}, skipped={$stats['skipped_exists']}, conflicts={$stats['conflicts']}, inserted={$stats['inserted']}, malformed={$stats['malformed']}, errors={$stats['errors']}");
}

logline("ALL done. Final stats: " . json_encode($stats));

if ($logHandle) fclose($logHandle);
if (isset($csvHandle) && $csvHandle && is_resource($csvHandle)) fclose($csvHandle);

exit(0);
