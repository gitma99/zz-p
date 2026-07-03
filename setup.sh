#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -euo pipefail

BASE_DIR="/srv"
DOCKER_COMPOSE_CMD="docker compose"

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
            DOCKER_COMPOSE_CMD="docker-compose"
        else
            ERROR "Docker Compose is not available. Please install docker-compose-plugin."
        fi
    fi
}

# 1. Install ZZPanel
install_zzbot() {
    check_prerequisites
    
    INFO "Creating base directory at $BASE_DIR..."
    mkdir -p "$BASE_DIR"
    cd "$BASE_DIR"

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

    # Register global command
    INFO "Installing setup script to /usr/local/bin/zzbot..."
    cp "$0" /usr/local/bin/zzbot
    chmod +x /usr/local/bin/zzbot

    INFO "Installation complete! You can now run this script anytime by typing 'zzbot' in the terminal."
}

# Ensure base directory exists for subsequent commands
ensure_installed() {
    if [ ! -d "$BASE_DIR" ] || [ ! -f "$BASE_DIR/docker-compose.yml" ]; then
        ERROR "ZZPanel is not installed yet. Please run option (1) Install first."
    fi
}

# 2. SSL Initializer
init_ssl() {
    ensure_installed
    cd "$BASE_DIR"
    local ssl_script="docker/nginx/init-letsencrypt.sh"
    if [ -f "$ssl_script" ]; then
        INFO "Running SSL Initialization..."
        chmod +x "$ssl_script"
        bash "$ssl_script"
    else
        ERROR "SSL Initializer script not found at $ssl_script"
    fi
}

# 3. Start
start_services() {
    ensure_installed
    cd "$BASE_DIR"
    INFO "Starting services..."
    $DOCKER_COMPOSE_CMD up -d
    INFO "Services started."
}

# 4. Stop
stop_services() {
    ensure_installed
    cd "$BASE_DIR"
    INFO "Stopping services..."
    $DOCKER_COMPOSE_CMD down
    INFO "Services stopped."
}

# 5. Restart
restart_services() {
    ensure_installed
    cd "$BASE_DIR"
    INFO "Restarting services..."
    $DOCKER_COMPOSE_CMD restart
    INFO "Services restarted."
}

# 6. Update
update_services() {
    ensure_installed
    cd "$BASE_DIR"
    INFO "Updating services..."
    $DOCKER_COMPOSE_CMD pull
    $DOCKER_COMPOSE_CMD up -d
    INFO "Update completed."
}

# 7. Uninstall
uninstall_zzbot() {
    read -p "Are you sure you want to completely uninstall ZZPanel and remove all data? (y/N): " -r RESPONSE
    if [[ "$RESPONSE" =~ ^([yY][eE][sS]|[yY])$ ]]; then
        if [ -d "$BASE_DIR" ] && [ -f "$BASE_DIR/docker-compose.yml" ]; then
            cd "$BASE_DIR"
            INFO "Stopping and removing containers & volumes..."
            $DOCKER_COMPOSE_CMD down -v || true
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

# 8. Backup
backup_services() {
    ensure_installed
    cd "$BASE_DIR"
    local backup_script="./docker/scripts/docker_backup.sh"
    if [ -f "$backup_script" ]; then
        chmod +x "$backup_script"
        bash "$backup_script"
    else
        ERROR "Backup script not found at $backup_script"
    fi
}

# 9. Restore
restore_services() {
    ensure_installed
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

# Main menu CLI loop
show_menu() {
    while true; do
        echo -e "\n========================================="
        echo -e "         ZZPanelbot Management           "
        echo -e "========================================="
        echo " 1) Install / Setup ZZPanel"
        echo " 2) Initialize SSL (Let's Encrypt)"
        echo " 3) Start Services"
        echo " 4) Stop Services"
        echo " 5) Restart Services"
        echo " 6) Update Services"
        echo " 7) Uninstall ZZPanel"
        echo " 8) Backup Database & Files"
        echo " 9) Restore Database & Files"
        echo " 0) Close"
        echo "========================================="
        read -p "Enter choice [0-9]: " -r CHOICE
        echo "========================================="

        case "$CHOICE" in
            1) install_zzbot ;;
            2) init_ssl ;;
            3) start_services ;;
            4) stop_services ;;
            5) restart_services ;;
            6) update_services ;;
            7) uninstall_zzbot ;;
            8) backup_services ;;
            9) restore_services ;;
            0) INFO "Exiting. Goodbye!"; exit 0 ;;
            *) WARN "Invalid option. Please try again." ;;
        esac
    done
}

# Execution start
check_root
show_menu
