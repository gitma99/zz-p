<?php
// Unit test: version normalization, downgrade check, and release asset parsing
// used by setup.sh and docker stack configuration.
require_once __DIR__ . '/helper.php';

// 1. Version normalization logic
function test_normalize_version($raw) {
    $trimmed = trim((string)$raw);
    if ($trimmed === '' || $trimmed === 'latest') {
        return 'latest';
    }
    if (preg_match('/^v[0-9]/', $trimmed)) {
        return $trimmed;
    }
    if (preg_match('/^[0-9]/', $trimmed)) {
        return 'v' . $trimmed;
    }
    return $trimmed;
}

assert_eq('latest', test_normalize_version(''), 'empty normalizes to latest');
assert_eq('latest', test_normalize_version('   '), 'whitespace normalizes to latest');
assert_eq('latest', test_normalize_version('latest'), 'latest stays latest');
assert_eq('v3.2.0', test_normalize_version('3.2.0'), '3.2.0 normalizes to v3.2.0');
assert_eq('v3.2.0', test_normalize_version('v3.2.0'), 'v3.2.0 preserved');
assert_eq('custom-tag', test_normalize_version('custom-tag'), 'custom-tag preserved');

// 2. Semver downgrade comparison logic
function test_is_downgrade($current, $target) {
    if ($current === 'unknown' || $current === 'latest' || $target === 'latest') {
        return false;
    }
    $c = ltrim($current, 'v');
    $t = ltrim($target, 'v');
    return version_compare($c, $t, '>');
}

assert_true(test_is_downgrade('v3.2.0', 'v3.1.0'), '3.2.0 to 3.1.0 is a downgrade');
assert_true(!test_is_downgrade('v3.1.0', 'v3.2.0'), '3.1.0 to 3.2.0 is an upgrade');
assert_true(!test_is_downgrade('v3.1.0', 'v3.1.0'), 'identical version is not a downgrade');
assert_true(!test_is_downgrade('latest', 'v3.1.0'), 'latest to version is not flagged as downgrade');
assert_true(!test_is_downgrade('unknown', 'v3.1.0'), 'unknown is not flagged as downgrade');

// 3. Asset extraction from GitHub API JSON
$sampleJson = json_encode([
    'tag_name' => 'v3.1.0',
    'assets' => [
        ['name' => 'other-asset.tar.gz', 'browser_download_url' => 'https://example.com/other.tar.gz'],
        ['name' => 'zzpanel-docker-setup.zip', 'browser_download_url' => 'https://example.com/download/zzpanel-docker-setup.zip']
    ]
]);

$decoded = json_decode($sampleJson, true);
$foundUrl = '';
foreach ($decoded['assets'] ?? [] as $asset) {
    if (strpos($asset['name'] ?? '', 'zzpanel-docker-setup.zip') !== false) {
        $foundUrl = $asset['browser_download_url'] ?? '';
        break;
    }
}
assert_eq('https://example.com/download/zzpanel-docker-setup.zip', $foundUrl, 'extracts expected asset URL from JSON');

// 4. Verify bash functions directly from setup.sh
$bashTest = <<<BASH
set -euo pipefail
td=$(mktemp -d)
BASE_DIR="\$td"
GITHUB_TOKEN=""

FATAL() { echo "[FATAL] \$*" >&2; exit 1; }
INFO() { :; }
WARN() { :; }

# Source target functions from setup.sh
source <(sed -n '/^extract_asset_url_from_json()/,/^}/p' setup.sh)
source <(sed -n '/^normalize_version_tag()/,/^}/p' setup.sh)
source <(sed -n '/^set_env_app_version()/,/^}/p' setup.sh)
source <(sed -n '/^get_installed_app_version()/,/^}/p' setup.sh)
source <(sed -n '/^confirm_downgrade_if_applicable()/,/^}/p' setup.sh)

# Fail fast on empty preconditions
if ( set_env_app_version "" ) 2>/dev/null; then exit 1; fi
if ( extract_asset_url_from_json "" "test" ) 2>/dev/null; then exit 1; fi
if ( extract_asset_url_from_json "test" "" ) 2>/dev/null; then exit 1; fi
if ( confirm_downgrade_if_applicable "" ) 2>/dev/null; then exit 1; fi

# Normalization
[ "\$(normalize_version_tag "")" = "latest" ]
[ "\$(normalize_version_tag "3.2.0")" = "v3.2.0" ]
[ "\$(normalize_version_tag "v3.2.0")" = "v3.2.0" ]

# Env persistence
touch "\$BASE_DIR/.env"
set_env_app_version "v3.5.0"
[ "\$(get_installed_app_version)" = "v3.5.0" ]

# Disk fallback overrides .env
mkdir -p "\$BASE_DIR/App"
echo '<?php \$APP_VERSION = "3.4.0";' > "\$BASE_DIR/App/vars.php"
[ "\$(get_installed_app_version)" = "3.4.0" ]

rm -rf "\$td"
echo "BASH_VERIFIED"
BASH;

$output = [];
$rc = 0;
exec('bash -c ' . escapeshellarg($bashTest) . ' 2>&1', $output, $rc);
assert_eq(0, $rc, 'Bash implementation self-check passed: ' . implode("\n", $output));

echo "OK setup_version_test.php\n";
exit(0);
