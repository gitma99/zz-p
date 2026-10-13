#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -euo pipefail

CURRENT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="/srv"
DOCKER_COMPOSE_CMD=(docker compose)
GITHUB_USERNAME="gitma99"
GITHUB_TOKEN="${GITHUB_TOKEN:-}"
VERSION="3.0.1"

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

get_current_script_version() {
    echo "$VERSION"
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

download_and_extract_latest_release() {
    INFO "Downloading latest release..."
    local zip_name="zzpanel-docker-setup.zip"
    local download_url="https://github.com/gitma99/zz-p/releases/latest/download/${zip_name}"
    local zip_file
    zip_file=$(mktemp /tmp/zzpanel-setup.XXXXXX.zip)
    if ! curl -fsSL -o "$zip_file" "$download_url"; then
        WARN "Direct download failed. Checking GitHub API..."
        local api_url="https://api.github.com/repos/gitma99/zz-p/releases/latest"
        local api_json
        api_json=$(curl -fsSL "$api_url") || FATAL "GitHub API request failed."
        local api_download_url=""
        if command -v python3 &> /dev/null; then
            api_download_url=$(printf '%s' "$api_json" | python3 -c "import json,sys; data=json.load(sys.stdin); print(next((a.get('browser_download_url','') for a in data.get('assets',[]) if '$zip_name' in a.get('name','')), ''))")
        else
            api_download_url=$(printf '%s' "$api_json" | grep -o "\"browser_download_url\"[[:space:]]*:[[:space:]]*\"[^\"]*$zip_name\"" | head -n 1 | cut -d '"' -f 4 || true)
        fi

        if [ -z "$api_download_url" ]; then
            rm -f "$zip_file"
            FATAL "Could not locate $zip_name in GitHub releases."
        fi
        curl -fsSL -o "$zip_file" "$api_download_url" || { rm -f "$zip_file"; FATAL "Download from GitHub API failed."; }
    fi

    INFO "Extracting files to $BASE_DIR..."
    validate_zip_paths "$zip_file"
    unzip -o -q "$zip_file" -d "$BASE_DIR" || { rm -f "$zip_file"; FATAL "Failed to extract release archive."; }
    rm -f "$zip_file"
}

# 1. Install ZZPanel
install_zzbot() {
    INFO "Installation Started..."

    check_prerequisites || return 1
    install_or_update_zzbot_script || return 1

    INFO "Creating base directory at $BASE_DIR..."
    mkdir -p "$BASE_DIR" || FATAL "Cannot create $BASE_DIR."
    cd "$BASE_DIR" || FATAL "Cannot enter $BASE_DIR."
    if [ -f "$BASE_DIR/docker-compose.yml" ]; then
        WARN "Existing install found at $BASE_DIR. Overwriting stack files; existing .env is kept."
    fi

    if [ -f .env ]; then
        backup_env_file .env || WARN "Could not back up existing .env — continuing without backup."
    fi
    download_and_extract_latest_release || return 1

    # Configure Environment
    if [ ! -f .env ]; then
        if [ -f .env.example ]; then
            cp .env.example .env
            INFO "Initialized .env from example."
        else
            touch .env
        fi
    fi

    INFO "Opening .env for configuration..."
    nano .env

    # pull images to ensure they are available before starting
    INFO "Pulling Docker images..."
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
        ERROR "Failed to start services. Check logs with option (12)." || return 1
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

# 6. Restart
restart_services() {
    require_installed || return 1
    INFO "Restarting services..."
    if run_compose_with_login down && run_compose_with_login up -d; then
        INFO "Services restarted."
    else
        ERROR "Restart failed. Check logs with option (12)." || return 1
    fi
}

# 7. Update
update_services() {
    require_installed || return 1

    if [ -f .env ]; then
        backup_env_file .env || FATAL "Cannot back up .env before update — aborting to avoid overwriting your config."
    fi
    download_and_extract_latest_release || return 1
    INFO "Updating services..."
    if run_compose_with_login pull; then
        INFO "Pull completed successfully."
    else
        ERROR "Pull failed even after logging in. Please check your credentials and network connection." || return 1
    fi

    if restart_services; then
        INFO "Update completed."
    else
        ERROR "Update applied but restart failed; run Restart (7) to finish."
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
        echo -e "\n========================================="
        echo -e "     ZZPanelBot Management ($VERSION)    "
        echo -e "========================================="
        echo " 1) Install ZZPanel"
        echo " 2) Install / Update zzbot menu"
        echo " 3) Edit .env"
        echo " 4) Initialize SSL (Let's Encrypt)"
        echo " 5) Start ZZPanelBot Services"
        echo " 6) Stop ZZPanelBot Services"
        echo " 7) Restart ZZPanelBot Services"
        echo " 8) Update ZZPanelBot Services"
        echo " 9) Uninstall ZZPanel"
        echo " 10) Backup Database & Files"
        echo " 11) Restore Database & Files"
        echo " 12) View Live Logs"
        echo " 13) View Logs for Specific Service"
        echo " 0) Close"
        echo "========================================="
        read -r -p "Enter choice [0-13]: " CHOICE
        echo "========================================="

        case "$CHOICE" in
            1) install_zzbot || true ;;
            2) install_or_update_zzbot_script || true ;;
            3) edit_env || true ;;
            4) init_ssl || true ;;
            5) start_services || true ;;
            6) stop_services || true ;;
            7) restart_services || true ;;
            8) update_services || true ;;
            9) uninstall_zzbot ;;
            10) backup_services || true ;;
            11) restore_services || true ;;
            12) show_live_logs || true ;;
            13) show_live_logs_for_service || true ;;
            0) INFO "Exiting. Goodbye!"; exit 0 ;;
            999) get_current_script_version ;;
            *) WARN "Invalid option. Please try again." ;;
        esac
    done
}


# Execution start
check_root
show_menu
