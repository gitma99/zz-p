#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -euo pipefail

CURRENT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="/srv"
DOCKER_COMPOSE_CMD=(docker compose)
GITHUB_USERNAME="gitma99"
GITHUB_TOKEN="${GITHUB_TOKEN:-}"
VERSION="3.1.0"

# Output coloring helper functions
INFO() { echo -e "\e[32m[INFO]\e[0m $*"; }
WARN() { echo -e "\e[33m[WARN]\e[0m $*"; }
ERROR() { echo -e "\e[31m[ERROR]\e[0m $*" >&2; return 1; }
FATAL() { ERROR "$*"; exit 1; }

# --- DIAGNOSTIC HELPERS START ---
DIAG_HAS_FAIL=0
DIAG_HAS_WARN=0
AUTO_FIX_CANDIDATES=()
DIAG_LOG_BUFFER=""

init_diagnose_state() {
    DIAG_HAS_FAIL=0
    DIAG_HAS_WARN=0
    AUTO_FIX_CANDIDATES=()
    DIAG_LOG_BUFFER=""
}

DIAG_PASS() {
    local msg="$*"
    echo -e "  \e[32m[PASS]\e[0m $msg"
    DIAG_LOG_BUFFER+=$'[PASS] '"$msg"$'\n'
}

DIAG_WARN() {
    local msg="$*"
    DIAG_HAS_WARN=1
    echo -e "  \e[33m[WARN]\e[0m $msg"
    DIAG_LOG_BUFFER+=$'[WARN] '"$msg"$'\n'
}

DIAG_FAIL() {
    local msg="$*"
    DIAG_HAS_FAIL=1
    echo -e "  \e[31m[FAIL]\e[0m $msg"
    DIAG_LOG_BUFFER+=$'[FAIL] '"$msg"$'\n'
}

DIAG_NOTE() {
    local msg="$*"
    echo -e "  \e[36m[INFO]\e[0m $msg"
    DIAG_LOG_BUFFER+=$'[INFO] '"$msg"$'\n'
}

mask_secret() {
    local raw="${1:-}"
    local secret="${2:-}"
    if [ -z "$secret" ] || [ -z "$raw" ]; then
        echo "$raw"
        return 0
    fi
    local masked_replacement
    if [[ "$secret" =~ ^([0-9]+):(.*)$ ]]; then
        local bot_id="${BASH_REMATCH[1]}"
        masked_replacement="${bot_id}:***REDACTED***"
    elif [ "${#secret}" -gt 6 ]; then
        masked_replacement="${secret:0:2}***${secret: -2}"
    else
        masked_replacement="***REDACTED***"
    fi
    echo "${raw//"$secret"/$masked_replacement}"
}

export_diagnose_report() {
    local target_file="${1:-}"
    [ -n "$target_file" ] || target_file="/tmp/zzbot-diagnose-$(date +%s).log"
    local clean_log
    clean_log=$(printf '%s\n' "$DIAG_LOG_BUFFER" | sed -E "s/\x1B\[([0-9]{1,3}(;[0-9]{1,2})?)?[mGK]//g")
    printf '%s\n' "$clean_log" > "$target_file"
    chmod 0600 "$target_file" 2>/dev/null || true
    echo "$target_file"
}

diagnose_host() {
    INFO "Running Host & System Resources Diagnostics..."
    
    # 1. Disk space check
    local root_free_mb srv_free_mb
    root_free_mb=$(df -m / 2>/dev/null | awk 'NR==2 {print $4}' || echo "0")
    if [ "$root_free_mb" -lt 1024 ]; then
        DIAG_FAIL "Root filesystem (/) has only ${root_free_mb}MB free space (< 1GB required)."
    elif [ "$root_free_mb" -lt 3072 ]; then
        DIAG_WARN "Root filesystem (/) has ${root_free_mb}MB free space (< 3GB remaining)."
    else
        DIAG_PASS "Root filesystem (/) has ${root_free_mb}MB free space."
    fi

    local base_dir="${BASE_DIR:-/srv}"
    if [ -d "$base_dir" ]; then
        srv_free_mb=$(df -m "$base_dir" 2>/dev/null | awk 'NR==2 {print $4}' || echo "$root_free_mb")
        if [ "$srv_free_mb" -ne "$root_free_mb" ]; then
            if [ "$srv_free_mb" -lt 1024 ]; then
                DIAG_FAIL "Base directory ($base_dir) has only ${srv_free_mb}MB free space."
            elif [ "$srv_free_mb" -lt 3072 ]; then
                DIAG_WARN "Base directory ($base_dir) has ${srv_free_mb}MB free space."
            else
                DIAG_PASS "Base directory ($base_dir) has ${srv_free_mb}MB free space."
            fi
        fi
    fi

    # 2. Inodes check
    local root_inode_pct
    root_inode_pct=$(df -i / 2>/dev/null | awk 'NR==2 {print $5}' | tr -d '%' || echo "0")
    if [ "$root_inode_pct" -gt 90 ]; then
        DIAG_WARN "Root filesystem inodes are ${root_inode_pct}% full (exhaustion risk)."
    else
        DIAG_PASS "Root filesystem inode usage is ${root_inode_pct}%."
    fi

    # 3. Available RAM
    if command -v free &>/dev/null; then
        local mem_avail_mb
        mem_avail_mb=$(free -m 2>/dev/null | awk '/^Mem:/ {print ($7 != "" ? $7 : $4)}' || echo "1024")
        if [ -n "$mem_avail_mb" ] && [ "$mem_avail_mb" -lt 256 ]; then
            DIAG_WARN "Available memory is low: ${mem_avail_mb}MB (< 256MB remaining)."
        else
            DIAG_PASS "Available memory: ${mem_avail_mb}MB."
        fi
    fi

    # 4. Ports 80 and 443 check
    local p80_proc p443_proc
    p80_proc=""
    p443_proc=""
    if command -v ss &>/dev/null; then
        p80_proc=$(ss -tlpn '( sport = :80 )' 2>/dev/null | grep -v 'Local Address' || true)
        p443_proc=$(ss -tlpn '( sport = :443 )' 2>/dev/null | grep -v 'Local Address' || true)
    elif command -v netstat &>/dev/null; then
        p80_proc=$(netstat -tlpn 2>/dev/null | grep ':80 ' || true)
        p443_proc=$(netstat -tlpn 2>/dev/null | grep ':443 ' || true)
    fi

    for port_info in "80:$p80_proc" "443:$p443_proc"; do
        local p="${port_info%%:*}"
        local info="${port_info#*:}"
        if [ -n "$info" ]; then
            if echo "$info" | grep -Eqi "docker|dockerd"; then
                DIAG_PASS "Port $p is bound by Docker."
            else
                DIAG_WARN "Port $p is occupied by a non-Docker process ($info)."
            fi
        else
            DIAG_PASS "Port $p is available or bound."
        fi
    done
}

scrape_container_crash_logs() {
    local container_name="${1:-}"
    local max_lines="${2:-15}"
    [ -n "$container_name" ] || return 0

    DIAG_NOTE "Recent logs from $container_name (last $max_lines lines):"
    local logs
    logs=$(docker logs --tail "$max_lines" "$container_name" 2>&1 || true)
    if [ -n "$logs" ]; then
        while IFS= read -r line; do
            [ -n "$line" ] && DIAG_NOTE "  [log] $line"
        done <<< "$logs"
    else
        DIAG_NOTE "  (No log output recorded for $container_name)"
    fi
}

diagnose_containers() {
    INFO "Running Docker Containers Diagnostics..."

    if ! docker info &>/dev/null; then
        DIAG_FAIL "Docker daemon is not running or socket is inaccessible."
        return 1
    fi

    local stack_services=(
        "app:zzpanel_app"
        "mysql:zzpanel_mysql"
        "nginx:zzpanel_nginx"
        "worker:zzpanel_worker"
        "certbot:zzpanel_certbot"
        "backup:zzpanel_backup"
        "phpmyadmin:zzpanel_phpmyadmin"
    )

    for item in "${stack_services[@]}"; do
        local sname="${item%%:*}"
        local cname="${item#*:}"

        local inspect_json
        inspect_json=$(docker inspect "$cname" 2>/dev/null || true)
        if [ -z "$inspect_json" ] || [ "$inspect_json" = "[]" ]; then
            if [[ "$sname" =~ ^(phpmyadmin|backup)$ ]]; then
                DIAG_WARN "Container $cname is not created."
            else
                DIAG_FAIL "Container $cname is missing / not created."
                AUTO_FIX_CANDIDATES+=("$sname")
            fi
            continue
        fi

        local status oom_killed exit_code health_status
        if command -v python3 &>/dev/null; then
            read -r status oom_killed exit_code health_status < <(python3 -c "
import sys, json
try:
    raw = json.loads(sys.argv[1])
    data = raw[0]['State'] if isinstance(raw, list) else (raw.get('State', raw) if isinstance(raw, dict) else {})
    status = data.get('Status', 'unknown')
    oom = 'true' if data.get('OOMKilled', False) else 'false'
    exit_c = str(data.get('ExitCode', 0))
    health = data.get('Health', {}).get('Status', '') if isinstance(data.get('Health'), dict) else ''
    print(f'{status} {oom} {exit_c} {health}')
except Exception:
    print('unknown false 0 ')
" "$inspect_json" 2>/dev/null || echo "unknown false 0 ")
        else
            status=$(echo "$inspect_json" | grep -o '"Status"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | cut -d '"' -f 4 || echo "unknown")
            oom_killed=$(echo "$inspect_json" | grep -o '"OOMKilled"[[:space:]]*:[[:space:]]*[a-zA-Z]*' | head -1 | awk '{print $2}' || echo "false")
            exit_code=$(echo "$inspect_json" | grep -o '"ExitCode"[[:space:]]*:[[:space:]]*[0-9]*' | head -1 | awk '{print $2}' || echo "0")
            health_status=$(echo "$inspect_json" | grep -o '"Health"[[:space:]]*:[[:space:]]*{[^}]*}' | grep -o '"Status"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | cut -d '"' -f 4 || true)
        fi

        if [ "$status" = "running" ]; then
            if [ -n "$health_status" ] && [ "$health_status" != "healthy" ]; then
                DIAG_FAIL "Container $cname is running but UNHEALTHY (health: $health_status)."
                scrape_container_crash_logs "$cname" 15
                AUTO_FIX_CANDIDATES+=("$sname")
            else
                local h_label=""
                [ -n "$health_status" ] && h_label=" (healthy)"
                DIAG_PASS "Container $cname is running${h_label}."
            fi
        else
            if [ "$oom_killed" = "true" ]; then
                DIAG_FAIL "Container $cname CRASHED due to Out-Of-Memory (OOMKilled=true, ExitCode=$exit_code)."
            else
                DIAG_FAIL "Container $cname is NOT running (Status=$status, ExitCode=$exit_code)."
            fi
            scrape_container_crash_logs "$cname" 15
            AUTO_FIX_CANDIDATES+=("$sname")
        fi
    done
}

diagnose_db() {
    INFO "Running Database & Schema Migrations Diagnostics..."

    local base_dir="${BASE_DIR:-/srv}"
    local env_file="$base_dir/.env"

    if [ ! -f "$env_file" ]; then
        DIAG_FAIL ".env file is missing at $env_file."
        return 1
    fi

    local db_name db_user db_pass root_pass
    db_name=$(grep -E '^[[:space:]]*DB_NAME=' "$env_file" 2>/dev/null | tail -1 | cut -d '=' -f 2- | tr -d ' "\r' || true)
    db_user=$(grep -E '^[[:space:]]*DB_USER=' "$env_file" 2>/dev/null | tail -1 | cut -d '=' -f 2- | tr -d ' "\r' || true)
    db_pass=$(grep -E '^[[:space:]]*DB_PASS=' "$env_file" 2>/dev/null | tail -1 | cut -d '=' -f 2- | tr -d ' "\r' || true)
    root_pass=$(grep -E '^[[:space:]]*MYSQL_ROOT_PASSWORD=' "$env_file" 2>/dev/null | tail -1 | cut -d '=' -f 2- | tr -d ' "\r' || true)

    if [ -z "$db_name" ] || [ -z "$db_user" ] || [ -z "$db_pass" ]; then
        DIAG_FAIL "Database configuration incomplete: DB_NAME, DB_USER, or DB_PASS is missing in .env."
        return 1
    fi

    # 1. MySQL ping
    local ping_pass="${root_pass:-$db_pass}"
    local ping_res
    ping_res=$(docker exec zzpanel_mysql mysqladmin ping -h localhost -uroot -p"$ping_pass" 2>&1 || true)
    if echo "$ping_res" | grep -qi "alive"; then
        DIAG_PASS "MySQL daemon responded to ping (mysqld alive)."
    else
        DIAG_FAIL "MySQL daemon ping failed: $ping_res"
        return 1
    fi

    # 2. Authenticate application user
    local auth_check
    auth_check=$(docker exec zzpanel_app php -r "
        \$m = @new mysqli('mysql', '$db_user', '$db_pass', '$db_name');
        if (\$m->connect_error) { echo 'ERR: ' . \$m->connect_error; } else { echo 'DB_AUTH_OK'; }
    " 2>/dev/null || echo "ERR: execution failed")

    if [[ "$auth_check" == *"DB_AUTH_OK"* ]]; then
        DIAG_PASS "Database authentication succeeded for user '$db_user'."
    else
        DIAG_FAIL "Database authentication failed: $auth_check"
    fi

    # 3. Connection saturation check
    local conn_info
    conn_info=$(docker exec zzpanel_mysql mysql -uroot -p"$ping_pass" -N -e "
        SHOW GLOBAL STATUS LIKE 'Threads_connected';
        SHOW GLOBAL VARIABLES LIKE 'max_connections';
    " 2>/dev/null || true)

    local active_conn max_conn
    active_conn=$(echo "$conn_info" | awk '/Threads_connected/ {print $2}')
    max_conn=$(echo "$conn_info" | awk '/max_connections/ {print $2}')
    if [ -n "$active_conn" ] && [ -n "$max_conn" ] && [ "$max_conn" -gt 0 ] 2>/dev/null; then
        local pct=$(( active_conn * 100 / max_conn ))
        if [ "$pct" -gt 85 ]; then
            DIAG_WARN "Database connection pool saturated: $active_conn / $max_conn active ($pct%)."
        else
            DIAG_PASS "Database connection pool healthy: $active_conn / $max_conn active ($pct%)."
        fi
    fi

    # 4. Check schema migrations
    local applied_migrations
    applied_migrations=$(docker exec zzpanel_mysql mysql -uroot -p"$ping_pass" -D "$db_name" -N -e "
        SELECT filename FROM schema_migrations;
    " 2>/dev/null || true)

    local mig_dir="$base_dir/sql/migrations"
    if [ -d "$mig_dir" ]; then
        local unapplied=()
        for mig_file in "$mig_dir"/*.sql; do
            [ -f "$mig_file" ] || continue
            local fname
            fname=$(basename "$mig_file")
            if ! echo "$applied_migrations" | grep -Fqx "$fname"; then
                unapplied+=("$fname")
            fi
        done
        if [ "${#unapplied[@]}" -gt 0 ]; then
            DIAG_WARN "Unapplied database migrations detected (${#unapplied[@]} pending: ${unapplied[*]})."
        else
            DIAG_PASS "Database schema migrations are up to date."
        fi
    fi
}

diagnose_web() {
    INFO "Running Web Server & PHP-FPM Runtime Diagnostics..."

    local base_dir="${BASE_DIR:-/srv}"

    # 1. Apache2 check
    if docker exec zzpanel_app apache2ctl -S &>/dev/null; then
        DIAG_PASS "Apache2 web server is running in zzpanel_app."
    else
        DIAG_FAIL "Apache2 is not running or syntax error in zzpanel_app."
    fi

    # 2. PHP-FPM check
    if docker exec zzpanel_app service php7.4-fpm status &>/dev/null; then
        DIAG_PASS "PHP7.4-FPM service is active in zzpanel_app."
    else
        DIAG_FAIL "PHP7.4-FPM service is inactive or crashed in zzpanel_app."
    fi

    # 3. HTTP loopback test
    local http_code
    http_code=$(docker exec zzpanel_app curl -s -o /dev/null -w "%{http_code}" http://localhost/ZZPanelBot/index.php 2>/dev/null || echo "000")
    if [ "$http_code" = "200" ]; then
        DIAG_PASS "Internal HTTP endpoint responded with HTTP 200 OK."
    elif [ "$http_code" = "423" ]; then
        DIAG_FAIL "Internal HTTP returned 423 Locked (Database connection failure in config.php)."
        scrape_container_crash_logs "zzpanel_app" 15
    elif [ "$http_code" = "500" ]; then
        DIAG_FAIL "Internal HTTP returned 500 Internal Server Error (PHP crash/fatal error)."
        scrape_container_crash_logs "zzpanel_app" 15
    else
        DIAG_WARN "Internal HTTP endpoint returned unexpected status: $http_code."
    fi

    # 4. Directory permissions check
    local perm_ok=1
    for d in "$base_dir/logs" "$base_dir/temp"; do
        if [ -d "$d" ]; then
            if [ ! -w "$d" ]; then
                perm_ok=0
                DIAG_FAIL "Host directory $d is not writable."
            fi
        fi
    done

    local container_perm_check
    container_perm_check=$(docker exec zzpanel_app su -s /bin/sh www-data -c "touch /var/www/html/ZZPanelBot/logs/.diag_test && rm -f /var/www/html/ZZPanelBot/logs/.diag_test && echo 'OK'" 2>/dev/null || echo "FAIL")
    if [ "$container_perm_check" != "OK" ] || [ "$perm_ok" -eq 0 ]; then
        DIAG_FAIL "Application logs or temp directories not writable by www-data."
        AUTO_FIX_CANDIDATES+=("permissions")
    else
        DIAG_PASS "Application directory permissions are valid (writable by www-data)."
    fi

    # 5. APCu cache check
    local apcu_loaded
    apcu_loaded=$(docker exec zzpanel_app php -r "echo extension_loaded('apcu') ? 'yes' : 'no';" 2>/dev/null || echo "no")
    if [ "$apcu_loaded" = "yes" ]; then
        DIAG_PASS "PHP APCu in-memory cache extension is loaded."
    else
        DIAG_WARN "PHP APCu extension is not loaded in zzpanel_app."
    fi
}
# --- DIAGNOSTIC HELPERS END ---

# Ensure the script is run with root/sudo privileges
check_root() {
    if [ "$EUID" -ne 0 ]; then
        FATAL "This script requires root or sudo privileges. Please run as root or with sudo."
    fi
}

github_curl() {
    if [ -n "$GITHUB_TOKEN" ]; then
        curl -H "Authorization: Bearer $GITHUB_TOKEN" "$@"
    else
        curl "$@"
    fi
}

extract_asset_url_from_json() {
    local json="${1:-}"
    local zip_name="${2:-}"
    [ -n "$json" ] || FATAL "extract_asset_url_from_json: json payload is required."
    [ -n "$zip_name" ] || FATAL "extract_asset_url_from_json: asset name is required."

    if command -v python3 &>/dev/null; then
        printf '%s' "$json" | python3 -c "import json,sys; data=json.load(sys.stdin); print(next((a.get('browser_download_url','') for a in data.get('assets',[]) if '$zip_name' in a.get('name','')), ''))"
    else
        printf '%s' "$json" | grep -o "\"browser_download_url\"[[:space:]]*:[[:space:]]*\"[^\"]*$zip_name\"" | head -n 1 | cut -d '"' -f 4 || true
    fi
}

get_current_script_version() {
    echo "$VERSION"
}

get_installed_app_version() {
    # 1. Query container (running or stopped)
    if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -q '^zzpanel_app$'; then
        if docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^zzpanel_app$'; then
            local live_ver
            live_ver=$(docker exec zzpanel_app php -r 'error_reporting(0); @include_once "/var/www/html/ZZPanelBot/App/vars.php"; if (isset($APP_VERSION) && $APP_VERSION !== "") { echo $APP_VERSION; }' 2>/dev/null || true)
            if [ -n "$live_ver" ]; then
                echo "$live_ver"
                return 0
            fi
        fi

        # Image tag on running or stopped container (strip digest if present)
        local image_tag
        image_tag=$(docker inspect --format '{{.Config.Image}}' zzpanel_app 2>/dev/null | sed -E 's/@sha256:.*//' | awk -F ':' '{print $NF}' || true)
        if [ -n "$image_tag" ] && [[ "$image_tag" != *"/"* ]]; then
            echo "$image_tag"
            return 0
        fi
    fi

    # 2. Query host application file if present
    if [ -f "$BASE_DIR/App/vars.php" ]; then
        local file_ver
        file_ver=$(grep -F '$APP_VERSION' "$BASE_DIR/App/vars.php" 2>/dev/null | head -n 1 | cut -d '=' -f 2 | tr -d ' ";\r\n' || true)
        if [ -n "$file_ver" ]; then
            echo "$file_ver"
            return 0
        fi
    fi

    # 3. Fallback to .env APP_VERSION
    if [ -f "$BASE_DIR/.env" ]; then
        local ver
        ver=$(grep -E '^[[:space:]]*APP_VERSION=' "$BASE_DIR/.env" 2>/dev/null | tail -n 1 | cut -d '=' -f 2- | tr -d ' "\r' || true)
        if [ -n "$ver" ]; then
            echo "$ver"
            return 0
        fi
    fi

    echo "unknown"
}

set_env_app_version() {
    local target_ver="${1:-}"
    [ -n "$target_ver" ] || FATAL "set_env_app_version: target version is required."
    local env_file="$BASE_DIR/.env"
    [ -f "$env_file" ] || return 0

    if grep -Eq '^[[:space:]]*APP_VERSION=' "$env_file"; then
        sed -i -E "s/^[[:space:]]*APP_VERSION=.*/APP_VERSION=${target_ver}/" "$env_file"
    else
        echo "APP_VERSION=${target_ver}" >> "$env_file"
    fi
}

normalize_version_tag() {
    local raw="${1:-}"
    raw=$(echo "$raw" | tr -d '[:space:]')
    if [ -z "$raw" ] || [ "$raw" = "latest" ]; then
        echo "latest"
        return 0
    fi
    if [[ "$raw" =~ ^v[0-9] ]]; then
        echo "$raw"
    elif [[ "$raw" =~ ^[0-9] ]]; then
        echo "v$raw"
    else
        echo "$raw"
    fi
}

RESOLVED_DOWNLOAD_URL=""
RESOLVED_APP_TAG=""

resolve_release_package() {
    local target_ver="${1:-}"
    [ -n "$target_ver" ] || FATAL "resolve_release_package: target version is required."

    local zip_name="zzpanel-docker-setup.zip"
    local direct_url
    RESOLVED_DOWNLOAD_URL=""
    RESOLVED_APP_TAG=""

    if [ "$target_ver" = "latest" ]; then
        direct_url="https://github.com/gitma99/zz-p/releases/latest/download/${zip_name}"
        if github_curl -fsSL -I "$direct_url" &>/dev/null; then
            RESOLVED_DOWNLOAD_URL="$direct_url"
            RESOLVED_APP_TAG="latest"
            return 0
        fi

        # Fallback to GitHub API
        local api_url="https://api.github.com/repos/gitma99/zz-p/releases/latest"
        local api_json
        api_json=$(github_curl -fsSL "$api_url" 2>/dev/null) || return 1
        local api_download_url
        api_download_url=$(extract_asset_url_from_json "$api_json" "$zip_name")
        if [ -n "$api_download_url" ]; then
            RESOLVED_DOWNLOAD_URL="$api_download_url"
            RESOLVED_APP_TAG="latest"
            return 0
        fi
    else
        local alt_ver
        if [[ "$target_ver" == v* ]]; then
            alt_ver="${target_ver#v}"
        else
            alt_ver="v${target_ver}"
        fi

        # 1. Direct release asset probe across candidates (no rate-limit)
        for candidate in "$target_ver" "$alt_ver"; do
            direct_url="https://github.com/gitma99/zz-p/releases/download/${candidate}/${zip_name}"
            if github_curl -fsSL -I "$direct_url" &>/dev/null; then
                RESOLVED_DOWNLOAD_URL="$direct_url"
                RESOLVED_APP_TAG="$candidate"
                return 0
            fi
        done

        # 2. Fallback to GitHub API by tag for both candidates
        for candidate in "$target_ver" "$alt_ver"; do
            local api_url="https://api.github.com/repos/gitma99/zz-p/releases/tags/${candidate}"
            local api_json
            api_json=$(github_curl -fsSL "$api_url" 2>/dev/null) || true
            if [ -n "$api_json" ]; then
                local api_download_url
                api_download_url=$(extract_asset_url_from_json "$api_json" "$zip_name")
                if [ -n "$api_download_url" ]; then
                    RESOLVED_DOWNLOAD_URL="$api_download_url"
                    RESOLVED_APP_TAG="$candidate"
                    return 0
                fi
            fi
        done
    fi

    return 1
}

confirm_downgrade_if_applicable() {
    local target_ver="${1:-}"
    [ -n "$target_ver" ] || FATAL "confirm_downgrade_if_applicable: target version is required."
    local current_ver
    current_ver=$(get_installed_app_version)

    if [ "$current_ver" = "unknown" ] || [ "$current_ver" = "latest" ] || [ "$target_ver" = "latest" ]; then
        return 0
    fi

    local current_clean="${current_ver#v}"
    local target_clean="${target_ver#v}"
    local is_downgrade="no"

    local oldest
    oldest=$(printf '%s\n%s\n' "$current_clean" "$target_clean" | sort -V 2>/dev/null | head -n 1 || echo "$current_clean")
    if [ "$oldest" = "$target_clean" ] && [ "$current_clean" != "$target_clean" ]; then
        is_downgrade="yes"
    fi

    if [ "$is_downgrade" = "yes" ]; then
        WARN "You are targeting version $target_ver, which is older than currently installed $current_ver."
        WARN "Database schemas may not be backward-compatible with older versions."
        read -r -p "Are you sure you want to proceed with this downgrade? (y/N): " reply
        if [[ ! "$reply" =~ ^[Yy]$ ]]; then
            INFO "Operation canceled by user."
            return 1
        fi
    fi

    return 0
}

# Ensure basic utilities are installed
check_and_install_tool() {
    local tool=$1
    if ! command -v "$tool" &> /dev/null; then
        WARN "$tool is not installed."
        if command -v apt-get &> /dev/null; then
            INFO "Attempting to install $tool..."
            apt-get update || FATAL "apt-get update failed."
            apt-get install -y "$tool" || FATAL "Failed to install $tool."
        else
            FATAL "Please install $tool manually and re-run this script."
        fi
    fi
}

check_prerequisites() {
    INFO "Checking prerequisites..."
    check_and_install_tool "curl"
    check_and_install_tool "unzip"
    check_and_install_tool "nano"
    check_and_install_tool "python3"

    # Check/install Docker
    if ! command -v docker &> /dev/null; then
        WARN "Docker is not installed."
        if command -v apt-get &> /dev/null; then
            INFO "Installing Docker..."
            apt-get update || FATAL "apt-get update failed."
            apt-get install -y ca-certificates curl || FATAL "Failed to install ca-certificates/curl."
            install -m 0755 -d /etc/apt/keyrings || FATAL "Cannot create /etc/apt/keyrings."
            curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc || FATAL "Failed to download Docker GPG key."
            chmod a+r /etc/apt/keyrings/docker.asc || FATAL "Cannot set permissions on Docker GPG key."

            tee /etc/apt/sources.list.d/docker.sources <<EOF || FATAL "Failed to write Docker apt source."
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

            apt-get update || FATAL "apt-get update failed after adding Docker repo."
            apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin || FATAL "Failed to install Docker packages."
            INFO "Docker installed successfully."
        else
            FATAL "Docker is not installed. Please install Docker manually."
        fi
    fi

    # Ensure docker compose is working
    if ! docker compose version &> /dev/null; then
        if command -v docker-compose &> /dev/null; then
            DOCKER_COMPOSE_CMD=(docker-compose)
        else
            FATAL "Docker Compose is not available. Please install docker-compose-plugin."
        fi
    fi
}

login_to_ghcr() {
    if [ -z "$GITHUB_TOKEN" ]; then
        read -s -r -p "Enter your GitHub token: " GITHUB_TOKEN
        echo
    fi
    if ! printf '%s' "$GITHUB_TOKEN" | docker login ghcr.io -u "$GITHUB_USERNAME" --password-stdin; then
        ERROR "Failed to log in to GitHub Container Registry. Please check your credentials." || return 1
    fi
}

run_compose_with_login() {
    local output_file
    local exit_code=0

    output_file=$(mktemp)

    set +e
    "${DOCKER_COMPOSE_CMD[@]}" "$@" 2>&1 | tee "$output_file"
    exit_code=${PIPESTATUS[0]}
    set -e

    if [ $exit_code -eq 0 ]; then
        rm -f "$output_file"
        return 0
    fi

    if grep -Eqi "unauthorized|authentication required|pull access denied|unauthenticated|requested access to the resource is denied" "$output_file"; then
        WARN "Docker reported an unauthorized error. Attempting to log in to GitHub Container Registry..."
        login_to_ghcr

        set +e
        "${DOCKER_COMPOSE_CMD[@]}" "$@" 2>&1 | tee "$output_file"
        exit_code=${PIPESTATUS[0]}
        set -e
    fi

    rm -f "$output_file"
    return $exit_code
}

backup_env_file() {
    local src="$1" dst
    dst=$(mktemp /tmp/zzpanel-env-backup.XXXXXX) || return 1
    install -m 600 "$src" "$dst" || { rm -f "$dst"; return 1; }
    prune_env_backups || true
    INFO "Backed up .env to $dst"
}

# Drop old .env backups, keep newest 3
prune_env_backups() {
    ls -1t /tmp/zzpanel-env-backup.* 2>/dev/null | tail -n +4 | xargs -r rm -f
}

validate_zip_paths() {
    local zip_file="$1"
    # zip-slip guard: reject entries that escape $BASE_DIR (absolute or ..) or are
    # symlinks/devices (a symlink entry can redirect later entries anywhere on disk)
    python3 - "$zip_file" <<'PY' || FATAL "Unsafe entry in release archive — aborting."
import sys, os, stat, zipfile
safe = True
with zipfile.ZipFile(sys.argv[1]) as zf:
    for info in zf.infolist():
        name = info.filename.replace('\\', '/')
        parts = name.split('/')
        if name.startswith('/') or '..' in parts:
            print(f"unsafe path entry: {name}", file=sys.stderr)
            safe = False
            continue
        mode = (info.external_attr >> 16) & 0o170000
        if mode in (stat.S_IFLNK, stat.S_IFSOCK, stat.S_IFIFO, stat.S_IFBLK, stat.S_IFCHR):
            print(f"unsafe special entry: {name}", file=sys.stderr)
            safe = False
sys.exit(0 if safe else 1)
PY
}

install_or_update_zzbot_script() {
    INFO "Install/Updating setup script to the latest version..."
    local tmp_file
    tmp_file=$(mktemp /tmp/zzbot.XXXXXX)
    if ! curl -fsSL -o "$tmp_file" https://raw.githubusercontent.com/gitma99/zz-p/main/setup.sh; then
        rm -f "$tmp_file"
        ERROR "Failed to download setup script." || return 1
    fi
    chmod 0755 "$tmp_file"
    mv "$tmp_file" /usr/local/bin/zzbot
    INFO "Setup script updated successfully."
}

download_and_extract_release() {
    local download_url="${1:-}"
    [ -n "$download_url" ] || FATAL "download_and_extract_release: download URL is required."

    INFO "Downloading release package from $download_url..."
    local zip_file
    zip_file=$(mktemp /tmp/zzpanel-setup.XXXXXX.zip)

    if ! github_curl -fsSL -o "$zip_file" "$download_url"; then
        rm -f "$zip_file"
        FATAL "Failed to download release archive from $download_url"
    fi

    INFO "Validating archive security..."
    validate_zip_paths "$zip_file"

    INFO "Extracting files to $BASE_DIR..."
    local stage_dir
    stage_dir=$(mktemp -d /tmp/zzpanel-stage.XXXXXX)
    unzip -o -q "$zip_file" -d "$stage_dir" || { rm -f "$zip_file"; rm -rf "$stage_dir"; FATAL "Failed to extract release archive."; }
    rm -f "$zip_file"
    cp -a "$stage_dir"/. "$BASE_DIR"/
    rm -rf "$stage_dir"
    INFO "Release files extracted successfully."
}

# 1. Install ZZPanel
install_zzbot() {
    local target_ver="${1:-}"
    if [ -z "$target_ver" ]; then
        read -r -p "Enter version to install [default: latest]: " target_ver
    fi
    target_ver=$(normalize_version_tag "$target_ver")

    INFO "Installation Started for version: $target_ver..."

    check_and_install_tool "curl"

    # Pre-flight resolution before any host mutation or script overwrites
    INFO "Locating release package for version: $target_ver..."
    if ! resolve_release_package "$target_ver"; then
        FATAL "Could not resolve release package for version: $target_ver"
    fi
    local download_url="$RESOLVED_DOWNLOAD_URL"
    local resolved_tag="$RESOLVED_APP_TAG"

    if [ -f "$BASE_DIR/docker-compose.yml" ]; then
        confirm_downgrade_if_applicable "$resolved_tag" || return 1
        WARN "Existing install found at $BASE_DIR. Overwriting stack files; existing .env is kept."
    fi

    check_prerequisites || return 1
    install_or_update_zzbot_script || return 1

    INFO "Creating base directory at $BASE_DIR..."
    mkdir -p "$BASE_DIR" || FATAL "Cannot create $BASE_DIR."
    cd "$BASE_DIR" || FATAL "Cannot enter $BASE_DIR."

    if [ -f .env ]; then
        backup_env_file .env || WARN "Could not back up existing .env — continuing without backup."
    fi

    download_and_extract_release "$download_url" || return 1

    # Configure Environment
    if [ ! -f .env ]; then
        if [ -f .env.example ]; then
            cp .env.example .env
            INFO "Initialized .env from example."
        else
            touch .env
        fi
    fi

    set_env_app_version "$resolved_tag"

    INFO "Opening .env for configuration..."
    nano .env

    # pull images to ensure they are available before starting
    INFO "Pulling Docker images for version: $resolved_tag..."
    if ! run_compose_with_login pull; then
        ERROR "Failed to pull Docker images. Please check your network connection and credentials." || return 1
    fi

    # initialize SSL if needed
    read -r -p "Do you want to initialize SSL certificates now? (y/N): " REPLY
    if [[ "$REPLY" =~ ^[Yy]$ ]]; then
        init_ssl || return 1
        INFO "Starting services..."
        start_services || return 1
    else
        WARN "You must initialize SSL later by running 'zzbot' and selecting the SSL option or the bot won't work properly."
    fi

    INFO "Installation complete! You can now run this script anytime by typing 'zzbot' in the terminal."
}

# Ensure base directory exists for subsequent commands
ensure_installed() {
    if [ ! -d "$BASE_DIR" ] || [ ! -f "$BASE_DIR/docker-compose.yml" ]; then
        ERROR "ZZPanel is not installed yet. Please run option (1) Install first." || return 1
    fi
}

# Common prelude for installed-stack commands
require_installed() {
    ensure_installed || return 1
    ensure_nginx_init_down
    cd "$BASE_DIR" || return 1
}

ensure_nginx_init_down() {
    if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -q '^zzpanel_nginx_init$'; then
        INFO "Stopping and removing existing Nginx init container..."
        docker stop zzpanel_nginx_init 2>/dev/null || true
        docker rm zzpanel_nginx_init 2>/dev/null || true
    fi
}

# Edit .env file
edit_env() {
    ensure_installed || return 1
    cd "$BASE_DIR" || return 1
    if [ -f .env ]; then
        nano .env
    else
        ERROR ".env file does not exist." || return 1
    fi
}

# 3. SSL Initializer
init_ssl() {
    require_installed || return 1
    local ssl_script="docker/nginx/init-letsencrypt.sh"
    if [ -f "$ssl_script" ]; then
        INFO "Running SSL Initialization..."
        chmod +x "$ssl_script"
        bash "$ssl_script"
    else
        ERROR "SSL Initializer script not found at $ssl_script" || return 1
    fi
}

# 4. Start
start_services() {
    require_installed || return 1
    INFO "Starting services..."
    if run_compose_with_login up -d; then
        INFO "Services started."
    else
        ERROR "Failed to start services. Check logs with option (13)." || return 1
    fi
}

# 5. Stop
stop_services() {
    require_installed || return 1
    INFO "Stopping services..."
    if "${DOCKER_COMPOSE_CMD[@]}" down; then
        INFO "Services stopped."
    else
        ERROR "Failed to stop services. Check running containers with: docker ps" || return 1
    fi
}

# 6. Soft Restart (docker compose restart)
soft_restart_services() {
    require_installed || return 1
    INFO "Soft restarting services (docker compose restart)..."
    if "${DOCKER_COMPOSE_CMD[@]}" restart; then
        INFO "Services soft-restarted."
    else
        ERROR "Soft restart failed. Check logs with option (13)." || return 1
    fi
}

# 7. Hard Restart (down + up)
hard_restart_services() {
    require_installed || return 1
    INFO "Hard restarting services (down + up)..."
    if run_compose_with_login down && run_compose_with_login up -d; then
        INFO "Services hard-restarted."
    else
        ERROR "Hard restart failed. Check logs with option (13)." || return 1
    fi
}

# 7. Update
update_services() {
    require_installed || return 1

    local target_ver="${1:-}"
    if [ -z "$target_ver" ]; then
        local current_ver
        current_ver=$(get_installed_app_version)
        read -r -p "Enter target version to update/switch to (current: $current_ver) [default: latest]: " target_ver
    fi
    target_ver=$(normalize_version_tag "$target_ver")

    # Pre-flight resolution before modifying host files or container state
    INFO "Locating release package for version: $target_ver..."
    if ! resolve_release_package "$target_ver"; then
        FATAL "Could not resolve release download URL for version: $target_ver"
    fi
    local download_url="$RESOLVED_DOWNLOAD_URL"
    local resolved_tag="$RESOLVED_APP_TAG"

    confirm_downgrade_if_applicable "$resolved_tag" || return 1

    if [ -f .env ]; then
        backup_env_file .env || FATAL "Cannot back up .env before update — aborting to avoid overwriting your config."
    fi

    download_and_extract_release "$download_url" || return 1
    set_env_app_version "$resolved_tag"

    INFO "Updating services with image version: $resolved_tag..."
    if ! run_compose_with_login pull; then
        FATAL "Pull failed even after logging in. Please check your credentials and network connection."
    fi
    INFO "Pull completed successfully."

    if hard_restart_services; then
        INFO "Update to $resolved_tag completed successfully."
    else
        ERROR "Update applied but hard restart failed; run Hard Restart (8) to finish."
    fi
}

# 8. Uninstall
uninstall_zzbot() {
    local response
    read -r -p "Are you sure you want to completely uninstall ZZPanel and remove all data? (y/N): " response
    if [[ "$response" =~ ^([yY][eE][sS]|[yY])$ ]]; then
        if [ -d "$BASE_DIR" ] && [ -f "$BASE_DIR/docker-compose.yml" ]; then
            cd "$BASE_DIR" || FATAL "Cannot enter $BASE_DIR."
            INFO "Stopping and removing containers & volumes..."
            "${DOCKER_COMPOSE_CMD[@]}" down -v || true
        fi

        INFO "Removing ZZPanel stack files in $BASE_DIR..."
        rm -f "$BASE_DIR/docker-compose.yml" "$BASE_DIR/docker-compose.build.yml" "$BASE_DIR/.env" "$BASE_DIR/.env.example"
        rm -rf "$BASE_DIR/docker" "$BASE_DIR/data" "$BASE_DIR/logs" "$BASE_DIR/sql" "$BASE_DIR/api" "$BASE_DIR/App" "$BASE_DIR/Templates" "$BASE_DIR/workers" "$BASE_DIR/Scripts" "$BASE_DIR/tools" "$BASE_DIR/docs"

        INFO "Removing global zzbot binary..."
        rm -f /usr/local/bin/zzbot

        INFO "Uninstallation finished."
        exit 0
    else
        INFO "Uninstall canceled."
    fi
}

# 9. Backup
backup_services() {
    require_installed || return 1
    local backup_script="./docker/scripts/docker_backup.sh"
    if [ -f "$backup_script" ]; then
        chmod +x "$backup_script"
        bash "$backup_script"
    else
        ERROR "Backup script not found at $backup_script" || return 1
    fi
}

# 10. Restore
restore_services() {
    require_installed || return 1
    local backup_script="./docker/scripts/docker_backup.sh"
    if [ -f "$backup_script" ]; then
        chmod +x "$backup_script"
        # Run restore mode. The original script asks selection options.
        bash "$backup_script"
    else
        ERROR "Backup script not found at $backup_script" || return 1
    fi
}

# 11. View Live Logs
show_live_logs() {
    require_installed || return 1
    INFO "Showing live logs (Press Ctrl+C to exit)..."
    "${DOCKER_COMPOSE_CMD[@]}" logs -f
}

# it gets one arg for service name and shows live logs for that service
show_live_logs_for_service() {
    require_installed || return 1
    local service_name
    read -r -p "Enter the service name to view logs (e.g., nginx, mysql, phpmyadmin): " service_name
    if [ -z "$service_name" ]; then
        ERROR "Service name cannot be empty." || return 1
    fi
    INFO "Showing live logs for $service_name (Press Ctrl+C to exit)..."
    "${DOCKER_COMPOSE_CMD[@]}" logs -f -- "$service_name"
}

# Main menu CLI loop
show_menu() {
    while true; do
        local app_ver
        app_ver=$(get_installed_app_version)
        echo -e "\n========================================="
        echo -e "     ZZPanelBot Management ($VERSION)    "
        echo -e "     Installed App Version: $app_ver     "
        echo -e "========================================="
        echo " 1) Install ZZPanel"
        echo " 2) Install / Update zzbot menu"
        echo " 3) Edit .env"
        echo " 4) Initialize SSL (Let's Encrypt)"
        echo " 5) Start ZZPanelBot Services"
        echo " 6) Stop ZZPanelBot Services"
        echo " 7) Soft Restart ZZPanelBot Services"
        echo " 8) Hard Restart ZZPanelBot Services"
        echo " 9) Update ZZPanelBot Services"
        echo " 10) Uninstall ZZPanel"
        echo " 11) Backup Database & Files"
        echo " 12) Restore Database & Files"
        echo " 13) View Live Logs"
        echo " 14) View Logs for Specific Service"
        echo " 0) Close"
        echo "========================================="
        read -r -p "Enter choice [0-14]: " CHOICE
        echo "========================================="

        case "$CHOICE" in
            1) install_zzbot || true ;;
            2) install_or_update_zzbot_script || true ;;
            3) edit_env || true ;;
            4) init_ssl || true ;;
            5) start_services || true ;;
            6) stop_services || true ;;
            7) soft_restart_services || true ;;
            8) hard_restart_services || true ;;
            9) update_services || true ;;
            10) uninstall_zzbot ;;
            11) backup_services || true ;;
            12) restore_services || true ;;
            13) show_live_logs || true ;;
            14) show_live_logs_for_service || true ;;
            0) INFO "Exiting. Goodbye!"; exit 0 ;;
            999) get_current_script_version ;;
            *) WARN "Invalid option. Please try again." ;;
        esac
    done
}


# Execution start
check_root

if [ $# -gt 0 ]; then
    cmd="$1"
    shift
    case "$cmd" in
        install)
            install_zzbot "${1:-}" || exit 1
            exit 0
            ;;
        update)
            update_services "${1:-}" || exit 1
            exit 0
            ;;
        *)
            WARN "Unknown command: $cmd"
            echo "Usage: zzbot [install [version] | update [version]]"
            exit 1
            ;;
    esac
fi

show_menu
