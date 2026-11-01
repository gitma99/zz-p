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
    awk -v r="$raw" -v s="$secret" -v repl="$masked_replacement" '
        BEGIN {
            idx = index(r, s)
            if (idx == 0) {
                printf "%s", r
                exit
            }
            res = ""
            while (idx > 0) {
                res = res substr(r, 1, idx - 1) repl
                r = substr(r, idx + length(s))
                idx = index(r, s)
            }
            res = res r
            printf "%s", res
        }
    '
}

get_env_var() {
    local key="${1:-}"
    local env_file="${2:-${BASE_DIR:-/srv}/.env}"
    if [ -z "$key" ]; then
        ERROR "get_env_var: key is required." || return 1
    fi
    [ -f "$env_file" ] || return 0
    grep -E "^[[:space:]]*${key}[[:space:]]*=" "$env_file" 2>/dev/null | tail -1 | cut -d '=' -f 2- | sed -E 's/^[[:space:]]*//; s/[[:space:]]*$//; s/^["'\''](.*)["'\'']$/\1/' || true
}

resolve_ipv4_host() {
    local target="${1:-}"
    if [ -z "$target" ]; then
        ERROR "resolve_ipv4_host: target host is required." || return 1
    fi
    local resolved=""
    if command -v getent &>/dev/null; then
        resolved=$(getent ahostsv4 "$target" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | head -1 | awk '{print $1}' || true)
        if [ -z "$resolved" ]; then
            resolved=$(getent ahosts "$target" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | head -1 | awk '{print $1}' || true)
        fi
    elif command -v dig &>/dev/null; then
        resolved=$(dig +short A "$target" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | tail -1 || true)
    elif command -v nslookup &>/dev/null; then
        resolved=$(nslookup -type=A "$target" 2>/dev/null | awk '/^Address: / {print $2}' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | tail -1 || true)
    fi
    echo "$resolved"
}

export_diagnose_report() {
    local target_file="${1:-}"
    [ -n "$target_file" ] || target_file="/tmp/zzbot-diagnose-$(date +%s).log"
    local clean_log
    clean_log=$(printf '%s\n' "$DIAG_LOG_BUFFER" | sed -E "s/\x1B\[([0-9]{1,3}(;[0-9]{1,2})?)?[mGK]//g")

    local base_dir="${BASE_DIR:-/srv}"
    local env_file="$base_dir/.env"
    if [ -f "$env_file" ]; then
        local sec
        for sec_var in BOT_TOKEN DB_PASS MYSQL_ROOT_PASSWORD; do
            sec=$(get_env_var "$sec_var" "$env_file")
            if [ -n "$sec" ]; then
                clean_log=$(mask_secret "$clean_log" "$sec")
            fi
        done
    fi

    printf '%s\n' "$clean_log" > "$target_file"
    chmod 0600 "$target_file" 2>/dev/null || true
    echo "$target_file"
}

diagnose_host() {
    INFO "Running Host & System Resources Diagnostics..."
    
    check_disk_path() {
        local path="$1"
        local label="$2"
        local free_mb
        free_mb=$(df -m "$path" 2>/dev/null | awk 'NR==2 {print $4}' || echo "0")
        if [ "$free_mb" -lt 1024 ]; then
            DIAG_FAIL "$label ($path) has only ${free_mb}MB free space (< 1GB required)."
        elif [ "$free_mb" -lt 3072 ]; then
            DIAG_WARN "$label ($path) has ${free_mb}MB free space (< 3GB remaining)."
        else
            DIAG_PASS "$label ($path) has ${free_mb}MB free space."
        fi
    }

    check_inode_path() {
        local path="$1"
        local label="$2"
        local inode_pct
        inode_pct=$(df -i "$path" 2>/dev/null | awk 'NR==2 {print $5}' | tr -d '%' || echo "0")
        if [ "$inode_pct" -gt 90 ]; then
            DIAG_WARN "$label ($path) inodes are ${inode_pct}% full (exhaustion risk)."
        else
            DIAG_PASS "$label ($path) inode usage is ${inode_pct}%."
        fi
    }

    # 1. Disk space check
    check_disk_path "/" "Root filesystem"
    local base_dir="${BASE_DIR:-/srv}"
    if [ -d "$base_dir" ]; then
        check_disk_path "$base_dir" "Base directory"
    fi

    # 2. Inodes check
    check_inode_path "/" "Root filesystem"
    if [ -d "$base_dir" ]; then
        check_inode_path "$base_dir" "Base directory"
    fi

    # 3. Available RAM & Swap
    if command -v free &>/dev/null; then
        local mem_avail_mb
        mem_avail_mb=$(free -m 2>/dev/null | awk '/^Mem:/ {print ($7 != "" ? $7 : $4)}' || echo "1024")
        if [ -n "$mem_avail_mb" ] && [ "$mem_avail_mb" -lt 256 ]; then
            DIAG_WARN "Available memory is low: ${mem_avail_mb}MB (< 256MB remaining)."
        else
            DIAG_PASS "Available memory: ${mem_avail_mb}MB."
        fi

        local swap_total swap_free
        swap_total=$(free -m 2>/dev/null | awk '/^Swap:/ {print $2}' || echo "0")
        swap_free=$(free -m 2>/dev/null | awk '/^Swap:/ {print $4}' || echo "0")
        if [ -n "$swap_total" ] && [ "$swap_total" -gt 0 ] 2>/dev/null; then
            if [ -n "$swap_free" ] && [ "$swap_free" -lt 64 ] 2>/dev/null; then
                DIAG_WARN "Available swap is low: ${swap_free}MB / ${swap_total}MB free."
            else
                DIAG_PASS "Available swap: ${swap_free}MB / ${swap_total}MB free."
            fi
        else
            DIAG_NOTE "Swap is not configured (0MB)."
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
    elif command -v lsof &>/dev/null; then
        p80_proc=$(lsof -iTCP:80 -sTCP:LISTEN -n -P 2>/dev/null || true)
        p443_proc=$(lsof -iTCP:443 -sTCP:LISTEN -n -P 2>/dev/null || true)
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
    if [ -z "$container_name" ]; then
        ERROR "scrape_container_crash_logs: container_name is required." || return 1
    fi

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
    db_name=$(get_env_var "DB_NAME" "$env_file")
    db_user=$(get_env_var "DB_USER" "$env_file")
    db_pass=$(get_env_var "DB_PASS" "$env_file")
    root_pass=$(get_env_var "MYSQL_ROOT_PASSWORD" "$env_file")

    if [ -z "$db_name" ] || [ -z "$db_user" ] || [ -z "$db_pass" ]; then
        DIAG_FAIL "Database configuration incomplete: DB_NAME, DB_USER, or DB_PASS is missing in .env."
    fi

    # 1. MySQL ping
    local ping_user="root"
    local ping_pass="$root_pass"
    if [ -z "$root_pass" ]; then
        ping_user="$db_user"
        ping_pass="$db_pass"
    fi

    local ping_res
    ping_res=$(docker exec zzpanel_mysql mysqladmin ping -h localhost -u"$ping_user" -p"$ping_pass" 2>&1 || true)
    if echo "$ping_res" | grep -qi "alive"; then
        DIAG_PASS "MySQL daemon responded to ping (mysqld alive)."
    else
        DIAG_FAIL "MySQL daemon ping failed: $ping_res"
    fi

    # 2. Authenticate application user (safely pass env vars)
    if [ -n "$db_name" ] && [ -n "$db_user" ] && [ -n "$db_pass" ]; then
        local auth_check
        auth_check=$(docker exec -e DB_USER="$db_user" -e DB_PASS="$db_pass" -e DB_NAME="$db_name" zzpanel_app php -r '
            $m = @new mysqli("mysql", getenv("DB_USER"), getenv("DB_PASS"), getenv("DB_NAME"));
            if ($m->connect_error) { echo "ERR: " . $m->connect_error; } else { echo "DB_AUTH_OK"; }
        ' 2>/dev/null || echo "ERR: execution failed")

        if [[ "$auth_check" == *"DB_AUTH_OK"* ]]; then
            DIAG_PASS "Database authentication succeeded for user '$db_user'."
        else
            DIAG_FAIL "Database authentication failed: $auth_check"
        fi
    fi

    # 3. Connection saturation check
    local conn_info
    conn_info=$(docker exec zzpanel_mysql mysql -u"$ping_user" -p"$ping_pass" -N -e "
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
    if [ -n "$db_name" ]; then
        local applied_migrations
        applied_migrations=$(docker exec zzpanel_mysql mysql -u"$ping_user" -p"$ping_pass" -D "$db_name" -N -e "
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
    fi
}

diagnose_web() {
    INFO "Running Web Server & PHP-FPM Runtime Diagnostics..."

    local base_dir="${BASE_DIR:-/srv}"

    # 1. Apache2 check
    if docker exec zzpanel_app apachectl status &>/dev/null || docker exec zzpanel_app service apache2 status &>/dev/null || docker exec zzpanel_app apache2ctl -S &>/dev/null; then
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
    http_code=$(docker exec zzpanel_app curl -s -m 5 --connect-timeout 5 -o /dev/null -w "%{http_code}" http://localhost/ZZPanelBot/index.php 2>/dev/null || echo "000")
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
        if [ ! -d "$d" ]; then
            perm_ok=0
            DIAG_FAIL "Host directory $d does not exist."
        elif [ ! -w "$d" ]; then
            perm_ok=0
            DIAG_FAIL "Host directory $d is not writable."
        fi
    done

    local container_perm_check
    container_perm_check=$(docker exec zzpanel_app su -s /bin/sh www-data -c "touch /var/www/html/ZZPanelBot/logs/.diag_test /var/www/html/ZZPanelBot/temp/.diag_test && rm -f /var/www/html/ZZPanelBot/logs/.diag_test /var/www/html/ZZPanelBot/temp/.diag_test && echo 'OK'" 2>/dev/null || echo "FAIL")
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

    # 6. Inline application error log tail
    local app_err_log=""
    if [ -d "$base_dir/logs" ]; then
        app_err_log=$(find "$base_dir/logs" -maxdepth 2 -type f \( -name "*error*.log" -o -name "*.log" \) 2>/dev/null | head -1 || true)
    fi
    if [ -n "$app_err_log" ] && [ -s "$app_err_log" ]; then
        DIAG_NOTE "Recent application error logs ($(basename "$app_err_log"), last 15 lines):"
        while IFS= read -r line; do
            [ -n "$line" ] && DIAG_NOTE "  [app-log] $line"
        done < <(tail -n 15 "$app_err_log" 2>/dev/null || true)
    else
        DIAG_PASS "No application error logs detected in $base_dir/logs."
    fi
}

diagnose_ssl() {
    INFO "Running Domain, SSL & Reverse Proxy Diagnostics..."

    local base_dir="${BASE_DIR:-/srv}"
    local env_file="$base_dir/.env"

    # 1. Nginx config syntax check
    local nginx_test
    nginx_test=$(docker exec zzpanel_nginx nginx -t 2>&1 || true)
    if echo "$nginx_test" | grep -qi "syntax is ok"; then
        DIAG_PASS "Nginx configuration syntax is valid."
    else
        DIAG_FAIL "Nginx configuration syntax test failed: $nginx_test"
    fi

    if [ ! -f "$env_file" ]; then
        DIAG_FAIL ".env file is missing at $env_file."
        return 1
    fi

    local domain
    domain=$(get_env_var "DOMAIN" "$env_file")
    if [ -z "$domain" ]; then
        DIAG_FAIL "DOMAIN is not set in $env_file."
        return 0
    fi

    # 2. SSL certificate check
    local cert_file="$base_dir/data/certbot/conf/live/$domain/fullchain.pem"
    if [ ! -f "$cert_file" ]; then
        DIAG_WARN "No SSL certificate found at $cert_file. Run option (4) Initialize SSL."
    else
        local end_date_str
        end_date_str=$(openssl x509 -enddate -noout -in "$cert_file" 2>/dev/null | cut -d '=' -f 2 || true)
        if [ -n "$end_date_str" ]; then
            local expiry_epoch current_epoch days_left
            expiry_epoch=$(date -d "$end_date_str" +%s 2>/dev/null || date -j -f "%b %d %T %Y %Z" "$end_date_str" +%s 2>/dev/null || echo "0")
            current_epoch=$(date +%s)
            if [ "$expiry_epoch" -gt 0 ]; then
                days_left=$(( (expiry_epoch - current_epoch) / 86400 ))
                if [ "$days_left" -lt 0 ]; then
                    DIAG_FAIL "SSL certificate for $domain is EXPIRED ($days_left days ago). Telegram will reject webhook!"
                elif [ "$days_left" -lt 15 ]; then
                    DIAG_WARN "SSL certificate for $domain expires soon ($days_left days remaining)."
                else
                    DIAG_PASS "SSL certificate for $domain is valid ($days_left days remaining)."
                fi
            fi
        fi
    fi

    # 3. Domain DNS resolution
    local resolved_ip
    resolved_ip=$(resolve_ipv4_host "$domain")

    if [ -n "$resolved_ip" ]; then
        local server_ip
        server_ip=$(curl -s4 -m 5 --connect-timeout 5 https://api.ipify.org 2>/dev/null || curl -s4 -m 5 --connect-timeout 5 https://ifconfig.me 2>/dev/null || echo "")
        if [ -n "$server_ip" ] && [ "$resolved_ip" != "$server_ip" ]; then
            DIAG_WARN "Domain DNS mismatch: $domain resolves to $resolved_ip, but server public IP is $server_ip."
        else
            DIAG_PASS "Domain $domain resolves to $resolved_ip."
        fi
    else
        DIAG_WARN "Could not resolve IP for domain $domain."
    fi

    # 4. Loopback HTTPS through Nginx
    local https_code
    https_code=$(curl -sk -m 5 --connect-timeout 5 -o /dev/null -w "%{http_code}" -H "Host: $domain" "https://127.0.0.1/ZZPanelBot/index.php" 2>/dev/null || echo "000")
    if [ "$https_code" = "200" ]; then
        DIAG_PASS "Reverse proxy HTTPS loopback test succeeded (HTTP 200)."
    elif [ "$https_code" = "444" ]; then
        DIAG_FAIL "Reverse proxy dropped connection with HTTP 444 (Host header mismatch or server_name misconfigured)."
    elif [ "$https_code" = "500" ] || [ "$https_code" = "502" ] || [ "$https_code" = "503" ] || [ "$https_code" = "504" ]; then
        DIAG_FAIL "Reverse proxy returned HTTP $https_code (Upstream app container failure or timed out)."
    elif [ "$https_code" = "000" ]; then
        DIAG_WARN "Reverse proxy HTTPS loopback probe failed (port 443 unreachable locally)."
    else
        DIAG_WARN "Reverse proxy answered HTTPS with status $https_code."
    fi
}

diagnose_telegram() {
    INFO "Running Telegram Network & Webhook Diagnostics..."

    local base_dir="${BASE_DIR:-/srv}"
    local env_file="$base_dir/.env"

    local bot_token="" domain=""
    if [ -f "$env_file" ]; then
        bot_token=$(get_env_var "BOT_TOKEN" "$env_file")
        domain=$(get_env_var "DOMAIN" "$env_file")
    else
        DIAG_FAIL ".env file is missing at $env_file."
    fi

    # Stage 1: DNS resolution for api.telegram.org
    local tg_ip
    tg_ip=$(resolve_ipv4_host "api.telegram.org")
    if [ -z "$tg_ip" ]; then
        if command -v getent &>/dev/null; then
            tg_ip=$(getent ahosts "api.telegram.org" 2>/dev/null | head -1 | awk '{print $1}' || true)
        fi
    fi

    if [ -z "$tg_ip" ]; then
        DIAG_FAIL "Stage 1 (DNS): Cannot resolve api.telegram.org. Likely DNS poisoning or broken nameservers."
        DIAG_NOTE "Troubleshooting: Change /etc/resolv.conf to 8.8.8.8 or 1.1.1.1."
    else
        DIAG_PASS "Stage 1 (DNS): api.telegram.org resolved to $tg_ip."
    fi

    # Stage 2: TCP/TLS egress handshake
    if ! curl -s -m 5 --connect-timeout 5 -I "https://api.telegram.org" &>/dev/null; then
        DIAG_FAIL "Stage 2 (TLS Egress): Cannot connect to api.telegram.org:443."
        DIAG_NOTE "Likely firewall blocking or national censorship/DPI packet drop. Bot cannot function without Telegram egress."
    else
        DIAG_PASS "Stage 2 (TLS Egress): Successfully established TLS connection to api.telegram.org."
    fi

    # Stage 3: getMe token auth check
    if [ -z "$bot_token" ]; then
        DIAG_FAIL "Stage 3 (Auth): BOT_TOKEN is not configured in .env."
    else
        local get_me_json
        get_me_json=$(curl -s -m 5 --connect-timeout 5 "https://api.telegram.org/bot${bot_token}/getMe" 2>/dev/null || true)
        local is_ok="" bot_uname=""
        if command -v python3 &>/dev/null; then
            read -r is_ok bot_uname < <(python3 -c "
import sys, json
try:
    d = json.loads(sys.argv[1])
    print(f\"{d.get('ok', False)} {d.get('result', {}).get('username', '')}\")
except Exception:
    print('False ')
" "$get_me_json" 2>/dev/null || echo "False ")
        else
            is_ok=$(echo "$get_me_json" | grep -o '"ok"[[:space:]]*:[[:space:]]*[a-zA-Z]*' | head -1 | awk '{print $2}' || echo "false")
            bot_uname=$(echo "$get_me_json" | grep -o '"username"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | cut -d '"' -f 4 || true)
        fi

        if [ "$is_ok" = "True" ] || [ "$is_ok" = "true" ]; then
            DIAG_PASS "Stage 3 (Auth): BOT_TOKEN is valid for bot @${bot_uname}."
        else
            local masked_tok
            masked_tok=$(mask_secret "$bot_token" "$bot_token")
            DIAG_FAIL "Stage 3 (Auth): BOT_TOKEN ($masked_tok) is invalid or rejected by Telegram."
        fi
    fi

    # Stage 4: getWebhookInfo status
    if [ -z "$bot_token" ]; then
        DIAG_FAIL "Stage 4 (Webhook URL): BOT_TOKEN is missing; cannot inspect webhook status."
    else
        local webhook_json
        webhook_json=$(curl -s -m 5 --connect-timeout 5 "https://api.telegram.org/bot${bot_token}/getWebhookInfo" 2>/dev/null || true)
        local wh_url="" wh_pending=0 wh_last_err="" wh_last_err_date=""
        if command -v python3 &>/dev/null; then
            IFS='|' read -r wh_url wh_pending wh_last_err wh_last_err_date < <(python3 -c "
import sys, json
try:
    d = json.loads(sys.argv[1]).get('result', {})
    url = d.get('url', '')
    pending = str(d.get('pending_update_count', 0))
    err = d.get('last_error_message', '').replace('\n', ' ')
    err_date = str(d.get('last_error_date', ''))
    print(f'{url}|{pending}|{err}|{err_date}')
except Exception:
    print('|||')
" "$webhook_json" 2>/dev/null || echo "|||")
        else
            wh_url=$(echo "$webhook_json" | grep -o '"url"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | cut -d '"' -f 4 || true)
            wh_pending=$(echo "$webhook_json" | grep -o '"pending_update_count"[[:space:]]*:[[:space:]]*[0-9]*' | head -1 | awk '{print $2}' || echo "0")
            wh_last_err=$(echo "$webhook_json" | grep -o '"last_error_message"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | cut -d '"' -f 4 || true)
            wh_last_err_date=$(echo "$webhook_json" | grep -o '"last_error_date"[[:space:]]*:[[:space:]]*[0-9]*' | head -1 | awk '{print $2}' || true)
        fi

        if [ -z "$domain" ]; then
            DIAG_FAIL "Stage 4 (Webhook URL): DOMAIN is not configured in .env."
        else
            local expected_url="https://${domain}/ZZPanelBot/index.php"
            if [ "$wh_url" = "$expected_url" ]; then
                DIAG_PASS "Stage 4 (Webhook URL): Telegram webhook is correctly set to $wh_url."
            elif [ -z "$wh_url" ]; then
                DIAG_FAIL "Stage 4 (Webhook URL): No webhook URL is registered with Telegram!"
                AUTO_FIX_CANDIDATES+=("webhook")
            else
                DIAG_WARN "Stage 4 (Webhook URL): Webhook URL is '$wh_url', expected '$expected_url'."
                AUTO_FIX_CANDIDATES+=("webhook")
            fi
        fi

        if [ -n "$wh_pending" ] && [ "$wh_pending" -gt 50 ] 2>/dev/null; then
            DIAG_WARN "Stage 4 (Backlog): Telegram update queue is backed up ($wh_pending pending updates)."
            AUTO_FIX_CANDIDATES+=("webhook")
        else
            DIAG_PASS "Stage 4 (Backlog): Pending update count is normal ($wh_pending updates)."
        fi

        if [ -n "$wh_last_err" ]; then
            local err_date_str=""
            if [ -n "$wh_last_err_date" ] && [ "$wh_last_err_date" -gt 0 ] 2>/dev/null; then
                err_date_str=" at $(date -d @"$wh_last_err_date" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -r "$wh_last_err_date" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "$wh_last_err_date")"
            fi
            DIAG_FAIL "Stage 4 (Delivery Error): Telegram reported error: '$wh_last_err'${err_date_str}."
            AUTO_FIX_CANDIDATES+=("webhook")
        else
            DIAG_PASS "Stage 4 (Delivery Error): No recent webhook delivery errors reported by Telegram."
        fi
    fi
}

apply_auto_fixes() {
    local interactive="${1:-true}"
    local base_dir="${BASE_DIR:-/srv}"
    local env_file="$base_dir/.env"

    if [ "${#AUTO_FIX_CANDIDATES[@]}" -eq 0 ]; then
        return 0
    fi

    local unique_candidates=()
    for item in "${AUTO_FIX_CANDIDATES[@]}"; do
        if [[ ! " ${unique_candidates[*]} " =~ " ${item} " ]]; then
            unique_candidates+=("$item")
        fi
    done

    echo -e "\n========================================="
    INFO "Recoverable issue(s) detected: ${unique_candidates[*]}"
    echo "========================================="

    if [ "$interactive" = "true" ]; then
        if [ ! -t 0 ]; then
            INFO "Non-interactive environment detected; skipping interactive auto-fixes (run with --fix to apply)."
            return 0
        fi
        local reply=""
        read -r -p "Do you want to apply safe auto-fixes now? (y/N): " reply || reply="n"
        if [[ ! "$reply" =~ ^[Yy]$ ]]; then
            INFO "Auto-fixes skipped by user."
            return 0
        fi
    fi

    local domain bot_token
    domain=$(get_env_var "DOMAIN" "$env_file")
    [ -n "$domain" ] || domain="${DOMAIN:-}"
    bot_token=$(get_env_var "BOT_TOKEN" "$env_file")
    [ -n "$bot_token" ] || bot_token="${BOT_TOKEN:-}"

    for candidate in "${unique_candidates[@]}"; do
        case "$candidate" in
            permissions)
                INFO "Fixing directory permissions on logs and temp..."
                mkdir -p "$base_dir/logs" "$base_dir/temp" 2>/dev/null || true
                chown -R www-data:www-data "$base_dir/logs" "$base_dir/temp" 2>/dev/null || true
                chmod -R 770 "$base_dir/logs" "$base_dir/temp" 2>/dev/null || true
                if docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^zzpanel_app$'; then
                    docker exec zzpanel_app mkdir -p /var/www/html/ZZPanelBot/logs /var/www/html/ZZPanelBot/temp 2>/dev/null || true
                    docker exec zzpanel_app chown -R www-data:www-data /var/www/html/ZZPanelBot/logs /var/www/html/ZZPanelBot/temp 2>/dev/null || true
                    docker exec zzpanel_app chmod -R 770 /var/www/html/ZZPanelBot/logs /var/www/html/ZZPanelBot/temp 2>/dev/null || true
                fi
                INFO "Permissions repaired."
                ;;
            webhook)
                if [ -n "$bot_token" ] && [ -n "$domain" ]; then
                    INFO "Re-registering Telegram webhook and clearing pending update backlog..."
                    local wh_url="https://${domain}/ZZPanelBot/index.php"
                    curl -s -m 5 --connect-timeout 5 -F "url=${wh_url}" "https://api.telegram.org/bot${bot_token}/setWebhook?drop_pending_updates=true" &>/dev/null || true
                    INFO "Webhook re-registered with drop_pending_updates=true."
                else
                    WARN "Cannot reset webhook: DOMAIN or BOT_TOKEN missing."
                fi
                ;;
            *)
                INFO "Restarting container/service: $candidate..."
                if [ -f "$base_dir/docker-compose.yml" ]; then
                    (cd "$base_dir" && "${DOCKER_COMPOSE_CMD[@]}" restart "$candidate" 2>/dev/null) || docker restart "zzpanel_${candidate}" 2>/dev/null || true
                else
                    docker restart "zzpanel_${candidate}" 2>/dev/null || true
                fi
                INFO "Restarted service $candidate."
                ;;
        esac
    done
}

diagnose_all() {
    local auto_fix="${1:-false}"
    local export_report="${2:-false}"

    init_diagnose_state
    echo -e "\n========================================="
    echo -e "       ZZPanel Health Check Suite        "
    echo -e "========================================="

    diagnose_host || true
    echo ""
    diagnose_containers || true
    echo ""
    diagnose_db || true
    echo ""
    diagnose_web || true
    echo ""
    diagnose_ssl || true
    echo ""
    diagnose_telegram || true

    echo -e "\n========================================="
    echo -e "           Diagnostics Summary           "
    echo -e "========================================="
    if [ "$DIAG_HAS_FAIL" -eq 1 ]; then
        echo -e "  Overall Status: \e[31mFAIL\e[0m (Issues requiring attention detected)"
    elif [ "$DIAG_HAS_WARN" -eq 1 ]; then
        echo -e "  Overall Status: \e[33mWARN\e[0m (Non-critical warnings detected)"
    else
        echo -e "  Overall Status: \e[32mPASS\e[0m (All systems operational)"
    fi
    echo -e "========================================="

    if [ "${#AUTO_FIX_CANDIDATES[@]}" -gt 0 ]; then
        if [ "$auto_fix" = "true" ]; then
            apply_auto_fixes false
        else
            apply_auto_fixes true
        fi
    fi

    if [ "$export_report" = "true" ]; then
        local saved_file
        saved_file=$(export_diagnose_report)
        INFO "Diagnostic report exported to: $saved_file"
    fi

    if [ "$DIAG_HAS_FAIL" -eq 1 ]; then
        return 1
    fi
    return 0
}

run_interactive_diagnose_category() {
    local cat="${1:-}"
    case "$cat" in
        host|containers|db|web|ssl|telegram) ;;
        *) ERROR "run_interactive_diagnose_category: invalid category '$cat'" || return 1 ;;
    esac
    init_diagnose_state
    "diagnose_${cat}" || true
    if [ "${#AUTO_FIX_CANDIDATES[@]}" -gt 0 ]; then
        apply_auto_fixes true
    fi
}

diagnose_menu() {
    while true; do
        echo -e "\n========================================="
        echo -e "       ZZPanel Diagnostics Menu         "
        echo -e "========================================="
        echo " 1) Run All Checks (Full Suite)"
        echo " 2) Host & System Resources"
        echo " 3) Docker Containers & Health"
        echo " 4) Database & Schema Migrations"
        echo " 5) Web Server & PHP-FPM Runtime"
        echo " 6) Domain, SSL & Reverse Proxy"
        echo " 7) Telegram Network & Webhook"
        echo " 0) Back to Main Menu"
        echo "========================================="
        read -r -p "Enter choice [0-7]: " DIAG_CHOICE
        echo "========================================="

        case "$DIAG_CHOICE" in
            1) diagnose_all false false || true ;;
            2) run_interactive_diagnose_category host ;;
            3) run_interactive_diagnose_category containers ;;
            4) run_interactive_diagnose_category db ;;
            5) run_interactive_diagnose_category web ;;
            6) run_interactive_diagnose_category ssl ;;
            7) run_interactive_diagnose_category telegram ;;
            0) return 0 ;;
            *) WARN "Invalid option. Please try again." ;;
        esac
    done
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
        echo " 15) Run Diagnostic Health Check"
        echo " 0) Close"
        echo "========================================="
        read -r -p "Enter choice [0-15]: " CHOICE
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
            15) diagnose_menu || true ;;
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
        diagnose)
            target_cat="menu"
            auto_fix="false"
            export_rep="false"

            while [ $# -gt 0 ]; do
                case "$1" in
                    --fix) auto_fix="true" ;;
                    --export) export_rep="true" ;;
                    all|host|containers|db|web|ssl|telegram) target_cat="$1" ;;
                    *) target_cat="$1" ;;
                esac
                shift
            done

            if [ "$target_cat" = "menu" ] && { [ "$auto_fix" = "true" ] || [ "$export_rep" = "true" ]; }; then
                target_cat="all"
            fi

            case "$target_cat" in
                menu)
                    diagnose_menu
                    exit 0
                    ;;
                all)
                    diagnose_all "$auto_fix" "$export_rep" || exit 1
                    exit 0
                    ;;
                host|containers|db|web|ssl|telegram)
                    init_diagnose_state
                    "diagnose_${target_cat}" || true
                    if [ "${#AUTO_FIX_CANDIDATES[@]}" -gt 0 ] && [ "$auto_fix" = "true" ]; then
                        apply_auto_fixes false
                    fi
                    if [ "$export_rep" = "true" ]; then
                        saved_file=$(export_diagnose_report)
                        INFO "Diagnostic report exported to: $saved_file"
                    fi
                    [ "$DIAG_HAS_FAIL" -eq 0 ] || exit 1
                    exit 0
                    ;;
                *)
                    WARN "Unknown diagnose category: $target_cat"
                    echo "Usage: zzbot diagnose [all|host|containers|db|web|ssl|telegram] [--fix] [--export]"
                    exit 1
                    ;;
            esac
            ;;
        *)
            WARN "Unknown command: $cmd"
            echo "Usage: zzbot [install [version] | update [version] | diagnose [category] [--fix] [--export]]"
            exit 1
            ;;
    esac
fi

show_menu
