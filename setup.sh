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
