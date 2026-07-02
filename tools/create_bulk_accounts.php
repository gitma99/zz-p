#!/usr/bin/env php
<?php
// tools/create_bulk_accounts.php

if (php_sapi_name() !== 'cli') {
	echo "This script must be run from the command line.\n";
	exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../App/apis/marzban-api/api.php';

$options = getopt('', ['panel:', 'plan:', 'count::', 'prefix::', 'user-id::', 'dry-run', 'verbose']);

$panelName = $options['panel'] ?? null;
$planName = $options['plan'] ?? null;

if (!$panelName || !$planName) {
	echo "Error: --panel and --plan are required.\n";
	echo "Usage: php tools/create_bulk_accounts.php --panel=panel_name --plan=plan_name [--count=300] [--prefix=ld_] [--user-id=999999999] [--dry-run] [--verbose]\n";
	exit(1);
}

$count = isset($options['count']) ? intval($options['count']) : 300;
$prefix = $options['prefix'] ?? 'ld_';
$userId = $options['user-id'] ?? '999999999';
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// Fetch panel details
$panelRes = $sql->query("SELECT * FROM `panels` WHERE `name` = '" . $sql->real_escape_string($panelName) . "' LIMIT 1");
if (!$panelRes || $panelRes->num_rows === 0) {
	echo "Error: Panel '$panelName' not found in database.\n";
	exit(1);
}
$panel = $panelRes->fetch_assoc();

if ($panel['type'] !== 'marzban') {
	echo "Error: Only 'marzban' panel type is supported currently.\n";
	exit(1);
}

// Fetch plan details
$planRes = $sql->query("SELECT * FROM `category` WHERE `name` = '" . $sql->real_escape_string($planName) . "' LIMIT 1");
if (!$planRes || $planRes->num_rows === 0) {
	echo "Error: Plan '$planName' not found in database.\n";
	exit(1);
}
$plan = $planRes->fetch_assoc();
$date = $plan['date'] ?? 0;
$limit = $plan['limit'] ?? 0;
$price = "0"; // Set price to 0 for load test accounts

echo "Target Panel: {$panel['name']} ({$panel['login_link']})\n";
echo "Target Plan: {$plan['name']} (Volume: {$limit}GB, Validity: {$date} days)\n";
echo "Accounts to create: $count\n";
echo "User ID to associate: $userId\n";
echo "Username prefix: $prefix\n";
if ($dryRun) {
	echo "--- DRY RUN MODE (No API calls or database insertions will be made) ---\n";
}

// Login and get token
$token = null;
if (!$dryRun) {
	echo "Authenticating to panel...\n";
	$loginRes = loginPanel($panel['login_link'], $panel['username'], $panel['password']);
	if (!$loginRes || !isset($loginRes['access_token'])) {
		echo "Error: Failed to authenticate to Marzban panel. Details: " . json_encode($loginRes) . "\n";
		exit(1);
	}
	$token = $loginRes['access_token'];
	echo "Authentication successful.\n";
}

// Set up proxies
$protocols = explode('|', $panel['protocols']);
unset($protocols[count($protocols) - 1]);
if ($protocols[0] == '') {
	unset($protocols[0]);
}
$proxies = array();
foreach ($protocols as $protocol) {
	if ($protocol == 'vless' and $panel['flow'] == 'flowon') {
		$proxies[$protocol] = array('flow' => 'xtls-rprx-vision');
	} else {
		$proxies[$protocol] = new stdClass();
	}
}

// Fetch inbounds
$panel_inbounds = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '{$panel['code']}'");
$inbound_rows = [];
if ($panel_inbounds) {
	while ($row = $panel_inbounds->fetch_assoc()) {
		$inbound_rows[] = $row['inbound'];
	}
}
$inbounds = array();
foreach ($protocols as $protocol) {
	$inbounds[$protocol] = $inbound_rows;
}
$inbounds_payload = (count($inbound_rows) > 0) ? $inbounds : 'all';

$successCount = 0;
$failCount = 0;

for ($i = 1; $i <= $count; $i++) {
	// Generate unique username code
	$random_suffix = rand(1000, 9999);
	$code_base = $prefix . str_pad($i, 4, '0', STR_PAD_LEFT) . '_' . $random_suffix;
	$code = $code_base . '_' . $userId;
	$name = $code;

	if ($verbose) {
		echo "[$i/$count] Preparing account '$name'...\n";
	}

	if ($dryRun) {
		if ($verbose) {
			echo "[$i/$count] Dry-run: Would create service '$name' with " . convertToBytes($limit . 'GB') . " bytes, expiry: " . date('Y-m-d H:i:s', strtotime("+ $date day")) . "\n";
			echo "[$i/$count] Dry-run: Would insert order into database with code_base: '$code_base'\n";
		}
		$successCount++;
		continue;
	}

	// Create service
	$expireTime = strtotime("+ $date day");
	$limitBytes = convertToBytes($limit . 'GB');
	
	$create_service = createService($name, $limitBytes, $expireTime, $proxies, $inbounds_payload, $token, $panel['login_link']);
	
	if ($create_service[1] !== false) {
		echo "[$i/$count] Failed to create service '$name': " . json_encode($create_service[1]) . "\n";
		$failCount++;
		continue;
	}

	$create_status = $create_service[0];
	$links = "";
	if (isset($create_status['links']) && is_array($create_status['links'])) {
		foreach ($create_status['links'] as $link) {
			$links .= $link . "\n\n";
		}
	}

	// Record in database orders
	$stmt = $sql->prepare("INSERT INTO `orders` (`from_id`, `location`, `protocol`, `date`, `volume`, `link`, `price`, `code`, `status`, `type`) VALUES (?, ?, 'null', ?, ?, ?, ?, ?, 'active', 'marzban')");
	if ($stmt) {
		$stmt->bind_param("sssssss", $userId, $panel['name'], $date, $limit, $links, $price, $code_base);
		$stmt->execute();
		$stmt->close();
		$successCount++;
		if ($verbose) {
			echo "[$i/$count] Service '$name' created and database record inserted successfully.\n";
		}
	} else {
		echo "[$i/$count] Failed to prepare DB insertion statement for '$name'. DB Error: " . $sql->error . "\n";
		$failCount++;
	}
}

echo "\n--- Summary ---\n";
echo "Total processed: $count\n";
echo "Successfully created: $successCount\n";
echo "Failed: $failCount\n";
