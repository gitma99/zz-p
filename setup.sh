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
ERROR() { echo -e "\e[31m[ERROR]\e[0m $*"; exit 1; }

# Ensure the script is run with root/sudo privileges
check_root() {
    if [ "$EUID" -ne 0 ]; then
        ERROR "This script requires root or sudo privileges. Please run as root or with sudo."
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
            apt-get update && apt-get install -y "$tool"
        else
            ERROR "Please install $tool manually and re-run this script."
        fi
    fi
}

check_prerequisites() {
    INFO "Checking prerequisites..."
    check_and_install_tool "curl"
    check_and_install_tool "unzip"
    check_and_install_tool "nano"

    # Check/install Docker
    if ! command -v docker &> /dev/null; then
        WARN "Docker is not installed."
        if command -v apt-get &> /dev/null; then
            INFO "Installing Docker..."
            apt-get update
            apt-get install -y ca-certificates curl
            install -m 0755 -d /etc/apt/keyrings
            curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
            chmod a+r /etc/apt/keyrings/docker.asc

            tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

            apt-get update
            apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
            INFO "Docker installed successfully."
        else
            ERROR "Docker is not installed. Please install Docker manually."
        fi
    fi

    # Ensure docker compose is working
    if ! docker compose version &> /dev/null; then
        if command -v docker-compose &> /dev/null; then
            DOCKER_COMPOSE_CMD=(docker-compose)
        else
            ERROR "Docker Compose is not available. Please install docker-compose-plugin."
        fi
    fi
}

login_to_ghcr() {
    if [ -z "$GITHUB_TOKEN" ]; then
        read -s -p "Enter your GitHub token: " GITHUB_TOKEN
        echo
    fi
    if ! docker login ghcr.io -u "$GITHUB_USERNAME" -p "$GITHUB_TOKEN"; then
        ERROR "Failed to log in to GitHub Container Registry. Please check your credentials."
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

    if grep -qi "unauthorized" "$output_file"; then
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

install_or_update_zzbot_script() {
    INFO "Install/Updating setup script to the latest version..."
    curl -L -o /tmp/zzbot https://raw.githubusercontent.com/gitma99/zz-p/main/setup.sh
    mv /tmp/zzbot /usr/local/bin/zzbot
    chmod +x /usr/local/bin/zzbot
    INFO "Setup script updated successfully."
}

download_and_extract_latest_release() {
    INFO "Downloading latest release..."
    ZIP_NAME="zzpanel-docker-setup.zip"
    DOWNLOAD_URL="https://github.com/gitma99/zz-p/releases/latest/download/${ZIP_NAME}"
    if ! curl -L -O -f "$DOWNLOAD_URL"; then
        WARN "Direct download failed. Checking GitHub API..."
        API_URL="https://api.github.com/repos/gitma99/zz-p/releases/latest"
        API_DOWNLOAD_URL=$(curl -s "$API_URL" | grep "browser_download_url" | grep "$ZIP_NAME" | head -n 1 | cut -d '"' -f 4 || true)
        
        if [ -z "$API_DOWNLOAD_URL" ]; then
            ERROR "Could not locate $ZIP_NAME in GitHub releases."
        fi
        curl -L -o "$ZIP_NAME" -f "$API_DOWNLOAD_URL"
    fi

    INFO "Extracting files to $BASE_DIR..."
    unzip -o "$ZIP_NAME"
    rm -f "$ZIP_NAME"
}

# 1. Install ZZPanel
install_zzbot() {
    INFO "Installation Started..."

    check_prerequisites
    install_or_update_zzbot_script

    INFO "Creating base directory at $BASE_DIR..."
    mkdir -p "$BASE_DIR"
    cd "$BASE_DIR"
    # check if basedir is empty if not empty cancell the installation
    # if [ "$(ls -A "$BASE_DIR")" ]; then
    #     ERROR "$BASE_DIR is not empty. Please ensure the directory is empty before proceeding with installation."
    # fi

    download_and_extract_latest_release

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
        ERROR "Failed to pull Docker images. Please check your network connection and credentials."
    fi

    # initialize SSL if needed
    read -p "Do you want to initialize SSL certificates now? (y/N): "
    if [[ "$REPLY" =~ ^[Yy]$ ]]; then
        init_ssl
        INFO "Starting services..."
        start_services
    else
        WARN "You must initialize SSL later by running 'zzbot' and selecting the SSL option or the bot won't work properly."
    fi


    INFO "Installation complete! You can now run this script anytime by typing 'zzbot' in the terminal."
}

# Ensure base directory exists for subsequent commands
ensure_installed() {
    if [ ! -d "$BASE_DIR" ] || [ ! -f "$BASE_DIR/docker-compose.yml" ]; then
        ERROR "ZZPanel is not installed yet. Please run option (1) Install first."
    fi
}

ensure_nginx_init_down() {
    if docker ps -a --format '{{.Names}}' | grep -q '^zzpanel_nginx_init$'; then
        INFO "Stopping and removing existing Nginx init container..."
        docker stop zzpanel_nginx_init 2>/dev/null || true
        docker rm zzpanel_nginx_init 2>/dev/null || true
    fi
}

# Edit .env file
edit_env() {
    ensure_installed
    cd "$BASE_DIR"
    if [ -f .env ]; then
        nano .env
    else
        ERROR ".env file does not exist."
    fi
}

# 3. SSL Initializer
init_ssl() {
    ensure_installed
    cd "$BASE_DIR"
    local ssl_script="docker/nginx/init-letsencrypt.sh"
    if [ -f "$ssl_script" ]; then
        INFO "Running SSL Initialization..."
        chmod +x "$ssl_script"
        ensure_nginx_init_down
        bash "$ssl_script"
    else
        ERROR "SSL Initializer script not found at $ssl_script"
    fi
}

# 4. Start
start_services() {
    ensure_installed
    ensure_nginx_init_down
    cd "$BASE_DIR"
    INFO "Starting services..."
    run_compose_with_login up -d
    INFO "Services started."
}

# 5. Stop
stop_services() {
    ensure_installed
    ensure_nginx_init_down
    cd "$BASE_DIR"
    INFO "Stopping services..."
    "${DOCKER_COMPOSE_CMD[@]}" down
    INFO "Services stopped."
}

# 6. Restart
restart_services() {
    ensure_installed
    ensure_nginx_init_down
    cd "$BASE_DIR"
    INFO "Restarting services..."
    "${DOCKER_COMPOSE_CMD[@]}" down && "${DOCKER_COMPOSE_CMD[@]}" up -d
    INFO "Services restarted."
}

# 7. Update
update_services() {
    ensure_installed
    ensure_nginx_init_down
    cd "$BASE_DIR"

    download_and_extract_latest_release
    INFO "Updating services..."
    if run_compose_with_login pull; then
        INFO "Pull completed successfully."
    else
        ERROR "Pull failed even after logging in. Please check your credentials and network connection."
    fi
    
    restart_services
    INFO "Update completed."
}

# 8. Uninstall
uninstall_zzbot() {
    read -p "Are you sure you want to completely uninstall ZZPanel and remove all data? (y/N): " -r RESPONSE
    if [[ "$RESPONSE" =~ ^([yY][eE][sS]|[yY])$ ]]; then
        if [ -d "$BASE_DIR" ] && [ -f "$BASE_DIR/docker-compose.yml" ]; then
            cd "$BASE_DIR"
            INFO "Stopping and removing containers & volumes..."
            "${DOCKER_COMPOSE_CMD[@]}" down -v || true
        fi
        
        INFO "Removing files in $BASE_DIR..."
        rm -rf "$BASE_DIR"
        
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
    ensure_installed
    ensure_nginx_init_down
    cd "$BASE_DIR"
    local backup_script="./docker/scripts/docker_backup.sh"
    if [ -f "$backup_script" ]; then
        chmod +x "$backup_script"
        bash "$backup_script"
    else
        ERROR "Backup script not found at $backup_script"
    fi
}

# 10. Restore
restore_services() {
    ensure_installed
    ensure_nginx_init_down
    cd "$BASE_DIR"
    local backup_script="./docker/scripts/docker_backup.sh"
    if [ -f "$backup_script" ]; then
        chmod +x "$backup_script"
        # Run restore mode. The original script asks selection options.
        bash "$backup_script"
    else
        ERROR "Backup script not found at $backup_script"
    fi
}

# 11. View Live Logs
show_live_logs() {
    ensure_installed
    cd "$BASE_DIR"
    INFO "Showing live logs (Press Ctrl+C to exit)..."
    "${DOCKER_COMPOSE_CMD[@]}" logs -f
}

# it gets one arg for service name and shows live logs for that service
show_live_logs_for_service() {
    ensure_installed
    cd "$BASE_DIR"
    read -p "Enter the service name to view logs (e.g., nginx, mysql, phpmyadmin): " SERVICE_NAME
    if [ -z "$SERVICE_NAME" ]; then
        ERROR "Service name cannot be empty."
    fi
    INFO "Showing live logs for $SERVICE_NAME (Press Ctrl+C to exit)..."
    "${DOCKER_COMPOSE_CMD[@]}" logs -f "$SERVICE_NAME"
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
        read -p "Enter choice [0-13]: " -r CHOICE
        echo "========================================="

        case "$CHOICE" in
            1) install_zzbot ;;
            2) install_or_update_zzbot_script ;;
            3) edit_env ;;
            4) init_ssl ;;
            5) start_services ;;
            6) stop_services ;;
            7) restart_services ;;
            8) update_services ;;
            9) uninstall_zzbot ;;
            10) backup_services ;;
            11) restore_services ;;
            12) show_live_logs ;;
            13) show_live_logs_for_service ;;
            0) INFO "Exiting. Goodbye!"; exit 0 ;;
            999) get_current_script_version ;;
            *) WARN "Invalid option. Please try again." ;;
        esac
    done
}


# Execution start
check_root
show_menu
