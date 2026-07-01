#!/bin/bash

# Exit immediately on error
set -euo pipefail
IFS=$'\n\t'

# -----------------------
# Defaults
# -----------------------
GITHUB_REPO_ADDRESS='https://github.com/gitma99/zz-p.git'
APP_DIR="/var/www/html/ZZPanelBot"
BOT_CONFIG_JSON_PATH="${APP_DIR}/bot_config.json"
CERTBOT_WEBROOT_PATH="/var/www/html"

INSTALLATION_PROOF_FILE="${APP_DIR}/index.php"

# Env/CLI args (defaults)
DOMAIN="${DOMAIN:-}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
BOT_TOKEN="${BOT_TOKEN:-}"
CHAT_ID="${CHAT_ID:-}"
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:-}"    # if empty -> use sudo mysql
DB_USER="${DB_USER:-}"
DB_PASS="${DB_PASS:-}"
RANDOM_DB_CREDS="${RANDOM_DB_CREDS:-false}"
NON_INTERACTIVE="${NON_INTERACTIVE:-false}"

# -----------------------
# Helpers
# -----------------------
log()    { printf "\e[1;34m[INFO]\e[0m %s\n" "$*"; }
ok()     { printf "\e[1;32m[OK]\e[0m %s\n" "$*"; }
warn()   { printf "\e[1;33m[WARN]\e[0m %s\n" "$*"; }
fatal()  { printf "\e[1;31m[ERROR]\e[0m %s\n" "$*"; exit 1; }

# Escape single quotes for use inside SQL single-quoted literals (replace ' with '')
sql_escape() { printf '%s' "$1" | sed "s/'/''/g"; }

colorized_echo() {
	local color=$1 text=$2
	case $color in
		red)      printf "\e[91m%s\e[0m\n" "${text}";;
		green)    printf "\e[92m%s\e[0m\n" "${text}";;
		yellow)   printf "\e[93m%s\e[0m\n" "${text}";;
		blue)     printf "\e[94m%s\e[0m\n" "${text}";;
		magenta)  printf "\e[95m%s\e[0m\n" "${text}";;
		cyan)     printf "\e[96m%s\e[0m\n" "${text}";;
		*)        printf "%s\n" "${text}";;
	esac
}

usage() {
	cat <<EOF
Usage: sudo $0 [options]

Options:
  --domain <domain>                         Domain (without https://)
  --email <email>                           Email for Let's Encrypt
  --bot-token <telegram-bot-token>          Telegram bot token
  --chat-id <admin-chat-id>                 Telegram admin chat id
  --mysql-root-pass <root-pass>             If provided, uses mysql -uroot -p<pass>, otherwise uses sudo mysql
  --db-username <dbuser>                    Database username
  --db-password <dbpass>                    Database password
  --random-db-username-password             Force generation of random DB username & password
  --non-interactive                         Run without prompts (fails if required options are missing)
  -h, --help                                Show this help
EOF
	exit 1
}

read_input() {
	local prompt=$1 default=$2 varname=$3
	local value

	while true; do
		if [[ -n "$default" ]]; then
			read -p "$prompt [default: $default]: " value
			value="${value:-$default}"   # if user presses Enter → default
		else
			read -p "$prompt: " value
			[[ -z "$value" ]] && { echo "❌ This field is required."; continue; }
		fi
		break
	done

	declare -n ref=$varname
	ref="$value"
}

# -----------------------
# Setup Cleanup / Trap
# -----------------------
MYSQL_CNF=$(mktemp)
cleanup() {
	rm -f "$MYSQL_CNF"
}
trap cleanup EXIT ERR

# -----------------------
# Parse arguments
# -----------------------
while [[ $# -gt 0 ]]; do
	case "$1" in
		--domain) DOMAIN="$2"; shift 2 ;;
		--email) CERTBOT_EMAIL="$2"; shift 2 ;;
		--bot-token|--token) BOT_TOKEN="$2"; shift 2 ;;
		--chat-id) CHAT_ID="$2"; shift 2 ;;
		--mysql-root-pass|--db-root-password) MYSQL_ROOT_PASS="$2"; shift 2 ;;
		--db-username) DB_USER="$2"; shift 2 ;;
		--db-password) DB_PASS="$2"; shift 2 ;;
		--random-db-username-password) RANDOM_DB_CREDS=true; shift ;;
		--non-interactive) NON_INTERACTIVE=true; shift ;;
		-h|--help) usage ;;
		*) echo "Unknown option: $1"; usage ;;
	esac
done

# -----------------------
# Pre-checks
# -----------------------
# Require root
if [[ "$(id -u)" -ne 0 ]]; then
	fatal "This script must be run as root (sudo)."
fi

# Require Ubuntu
if [[ ! -f /etc/os-release ]]; then
	fatal "Cannot determine OS. This script only supports Ubuntu."
fi
. /etc/os-release
if [[ "$ID" != "ubuntu" ]]; then
	fatal "This script only supports Ubuntu."
fi
if [[ "$VERSION_ID" != 20.* ]]; then
	warn "This script was designed for Ubuntu 20.x. Running on Ubuntu $VERSION_ID might have compatibility issues."
fi
log "Running on Ubuntu $VERSION_ID"

if [ -e "$INSTALLATION_PROOF_FILE" ]; then
	fatal "Bot already installed. Uninstall first."
fi

if [ -d "$APP_DIR" ] && [ "$(ls -A "$APP_DIR" 2>/dev/null)" ]; then
	fatal "Target directory $APP_DIR exists and is not empty. Aborting installation to prevent data loss."
fi

# -----------------------
# Handle Inputs (Interactive vs Non-Interactive)
# -----------------------
if [[ "$NON_INTERACTIVE" == "true" || ! -t 0 ]]; then
	[[ -z "$DOMAIN" ]] && fatal "DOMAIN is required but not set in non-interactive mode."
	[[ -z "$CERTBOT_EMAIL" ]] && CERTBOT_EMAIL="admin@example.com"
	[[ -z "$BOT_TOKEN" ]] && fatal "BOT_TOKEN is required but not set in non-interactive mode."
	[[ -z "$CHAT_ID" ]] && fatal "CHAT_ID is required but not set in non-interactive mode."
else
	[[ -z "$DOMAIN" ]]         && read_input "Enter domain (without http/https)" "" DOMAIN
	[[ -z "$CERTBOT_EMAIL" ]]  && read_input "Enter contact email for Let's Encrypt" "admin@example.com" CERTBOT_EMAIL
	[[ -z "$BOT_TOKEN" ]]      && read_input "Enter Telegram bot token" "" BOT_TOKEN
	[[ -z "$CHAT_ID" ]]        && read_input "Enter Telegram chat ID" "" CHAT_ID
fi

# -----------------------
# Credential Initialization
# -----------------------
# Generate random password for phpMyAdmin
PHP_MYADMIN_PASS=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)

if [[ "$RANDOM_DB_CREDS" == "true" ]]; then
	DB_USER=$(pwgen -A 8 1)
	DB_PASS=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)
else
	if [[ -z "$DB_USER" ]]; then
		if [[ "$NON_INTERACTIVE" == "true" || ! -t 0 ]]; then
			DB_USER=$(pwgen -A 8 1)
		else
			rand_db_user=$(pwgen -A 8 1)
			read_input "Enter DB username" "$rand_db_user" DB_USER
		fi
	fi
	if [[ -z "$DB_PASS" ]]; then
		if [[ "$NON_INTERACTIVE" == "true" || ! -t 0 ]]; then
			DB_PASS=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)
		else
			rand_db_pass=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)
			read_input "Enter DB password" "$rand_db_pass" DB_PASS
		fi
	fi
fi

# Configure MySQL CLI Credentials Extra File securely
if [[ -n "$MYSQL_ROOT_PASS" ]]; then
	cat > "$MYSQL_CNF" <<EOF
[client]
user=root
password=${MYSQL_ROOT_PASS}
EOF
	MYSQL_OPTS="--defaults-extra-file=$MYSQL_CNF"
else
	MYSQL_OPTS=""
fi

log "Installation started. Please wait..."

# -----------------------
# Install Packages
# -----------------------
log "Updating system packages list..."
apt update -y

log "Installing required packages..."
apt install -y dialog curl jq ufw git wget unzip pwgen sshpass \
	mysql-server apache2 php-fpm php-apcu \
	php php-mbstring php-zip php-gd php-json php-curl php-soap php-ssh2 \
	libssh2-1-dev libssh2-1 phpmyadmin certbot python3-certbot-apache

# Validate jq is available
if ! command -v jq &> /dev/null; then
	fatal "jq installation failed or is not in PATH."
fi

# -----------------------
# Configure phpmyadmin
# -----------------------
log "Configuring phpMyAdmin..."
debconf-set-selections <<< "phpmyadmin phpmyadmin/app-password-confirm password ${PHP_MYADMIN_PASS}"
debconf-set-selections <<< "phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2"
debconf-set-selections <<< "phpmyadmin phpmyadmin/mysql/admin-pass password ${PHP_MYADMIN_PASS}"
debconf-set-selections <<< "phpmyadmin phpmyadmin/mysql/app-pass password ${PHP_MYADMIN_PASS}"
debconf-set-selections <<< "phpmyadmin phpmyadmin/dbconfig-install boolean true"

ln -sf /etc/phpmyadmin/apache.conf /etc/apache2/conf-available/phpmyadmin.conf
a2enconf phpmyadmin.conf

# Configure Apache to use event MPM and PHP-FPM
log "Configuring Apache Event MPM and PHP-FPM..."
a2dismod mpm_prefork || true
for php_mod in $(ls /etc/apache2/mods-enabled/php*.load 2>/dev/null | xargs -n1 basename | sed 's/\.load//'); do
	a2dismod "$php_mod" || true
done
a2enmod mpm_event proxy_fcgi setenvif

# Enable and start services
systemctl enable --now mysql apache2

# Detect PHP version and enable FPM configuration
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
a2enconf "php${PHP_VER}-fpm" || warn "Could not enable php${PHP_VER}-fpm configuration automatically."
systemctl enable --now "php${PHP_VER}-fpm" || warn "Could not enable php${PHP_VER}-fpm service."

# -----------------------
# Clone Bot Repository
# -----------------------
log "Cloning application repository..."
git clone "$GITHUB_REPO_ADDRESS" "$APP_DIR"
mkdir -p "${APP_DIR}/logs" "${APP_DIR}/temp"
ok "Application files cloned to $APP_DIR"

# -----------------------
# SSL setup
# -----------------------
log "Setting up SSL for domain $DOMAIN..."
ufw allow 80/tcp
ufw allow 443/tcp
systemctl enable certbot.timer
certbot --apache --agree-tos --non-interactive \
	--email "$CERTBOT_EMAIL" -d "$DOMAIN" \
	|| fatal "Certbot failed for ${DOMAIN} (ensure the domain points to this server and port 80 is reachable)."
ok "SSL certificate installed for $DOMAIN"

# -----------------------
# Database Config
# -----------------------
DB_NAME="BotPanel_$(openssl rand -hex 2)"
escaped_dbname="$(sql_escape "${DB_NAME}")"
escaped_dbuser="$(sql_escape "${DB_USER}")"
escaped_dbpass="$(sql_escape "${DB_PASS}")"

SQL=$(cat <<SQL
CREATE DATABASE IF NOT EXISTS \`${escaped_dbname}\`;
CREATE USER IF NOT EXISTS '${escaped_dbuser}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${escaped_dbpass}';
GRANT ALL PRIVILEGES ON \`${escaped_dbname}\`.* TO '${escaped_dbuser}'@'localhost';
FLUSH PRIVILEGES;
SQL
)

log "Creating database '${DB_NAME}' and user '${DB_USER}'..."
mysql ${MYSQL_OPTS} -e "$SQL"
ok "Database and user created."

# -----------------------
# Configuration files
# -----------------------

config_template_path="${APP_DIR}/Templates/bot_config_template.json"
if [[ ! -f "$config_template_path" ]]; then
	fatal "Configuration template not found at $config_template_path"
fi

TEMP_CONFIG=$(mktemp)
jq --arg dom "$DOMAIN" \
   --arg tok "$BOT_TOKEN" \
   --arg dev "$CHAT_ID" \
   --arg dbn "$DB_NAME" \
   --arg dbu "$DB_USER" \
   --arg dbp "$DB_PASS" \
   '.main_domin = $dom | .token = $tok | .dev = $dev | .database.db_name = $dbn | .database.db_username = $dbu | .database.db_password = $dbp' \
   "$config_template_path" > "$TEMP_CONFIG"
mv "$TEMP_CONFIG" "$BOT_CONFIG_JSON_PATH"

# -----------------------
# File Permissions & Ownership
# -----------------------
log "Setting secure file permissions..."
find "$APP_DIR" -type d -exec chmod 750 {} +
find "$APP_DIR" -type f -exec chmod 640 {} +
chmod +x "${APP_DIR}"/*.sh 2>/dev/null || true
chmod -R 770 "${APP_DIR}/temp" "${APP_DIR}/logs"
chown -R www-data:www-data "$APP_DIR"
ok "Permissions updated."

# -----------------------
# Schema Initialization
# -----------------------
log "Running database schema initialization..."
SQL_INIT_OUT="${APP_DIR}/temp/sql_init_output.json"
php -r '
$_GET["db_username"] = $argv[1];
$_GET["db_password"] = $argv[2];
$_GET["db_name"] = $argv[3];
include "'"${APP_DIR}"'/sql/sql.php";
' -- "$DB_USER" "$DB_PASS" "$DB_NAME" > "$SQL_INIT_OUT" || fatal "Database schema initialization execution failed."

if [[ -f "$SQL_INIT_OUT" ]]; then
	status=$(jq -r '.status' "$SQL_INIT_OUT" 2>/dev/null || echo "false")
	msg=$(jq -r '.msg' "$SQL_INIT_OUT" 2>/dev/null || echo "Unknown error")
	rm -f "$SQL_INIT_OUT"
	if [[ "$status" != "true" ]]; then
		fatal "Database initialization failed: $msg"
	fi
else
	fatal "Database schema initialization script returned no output."
fi
ok "Database schema initialized successfully."

# -----------------------
# Configure Performance Settings
# -----------------------
log "Applying PHP performance tuning (OPcache & APCu)..."
PHP_FPM_CONF_DIR="/etc/php/${PHP_VER}/fpm/conf.d"
if [ -d "$PHP_FPM_CONF_DIR" ]; then
	cat > "${PHP_FPM_CONF_DIR}/99-botpanel-perf.ini" <<EOF
; BotPanel Performance Tuning Configuration
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.validate_timestamps=1

apc.enabled=1
apc.enable_cli=1
apc.shm_size=32M

memory_limit=128M
max_execution_time=120
post_max_size=16M
upload_max_filesize=16M
EOF
	ok "PHP performance tuning configuration created."
	systemctl restart "php${PHP_VER}-fpm" || true
else
	warn "PHP FPM config directory not found at $PHP_FPM_CONF_DIR. Skipping FPM config tuning."
fi

log "Applying Apache performance tuning (Compression, Keep-Alive, Event MPM)..."
APACHE_PERF_CONF="/etc/apache2/conf-available/botpanel-perf.conf"
cat > "$APACHE_PERF_CONF" <<EOF
# BotPanel Apache Performance Config

# Gzip compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/json text/html text/plain text/css application/javascript text/xml
</IfModule>

# Keep-Alive Settings
KeepAlive On
KeepAliveTimeout 5
MaxKeepAliveRequests 100

# MPM Event Configuration tuning for high concurrent webhook request volume
<IfModule mpm_event_module>
    StartServers            2
    MinSpareThreads         25
    MaxSpareThreads         75
    ThreadLimit             64
    ThreadsPerChild         25
    MaxRequestWorkers       150
    MaxConnectionsPerChild  1000
</IfModule>
EOF

a2enconf botpanel-perf || warn "Failed to enable Apache performance configuration."

# Restart Apache to apply all modules/configurations
systemctl restart apache2

# -----------------------
# Webhook and Notifications
# -----------------------
colorized_echo blue "\nSetting Telegram Webhook..."
WEBHOOK_URL="https://${DOMAIN}/ZZPanelBot/index.php"
WEBHOOK_RES=$(curl -s -F "url=${WEBHOOK_URL}" "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook")

if echo "$WEBHOOK_RES" | grep -q '"ok":true'; then
	ok "Webhook set successfully to ${WEBHOOK_URL}"
else
	warn "Failed to set Telegram webhook: ${WEBHOOK_RES}"
fi

log "Sending installation status notification..."
TEXT_MESSAGE="✅ Bot has been successfully installed!"
MSG_RES=$(curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
	-d chat_id="${CHAT_ID}" -d text="${TEXT_MESSAGE}")

if echo "$MSG_RES" | grep -q '"ok":true'; then
	ok "Notification sent to chat ID ${CHAT_ID}"
else
	warn "Failed to send Telegram notification: ${MSG_RES}"
fi

colorized_echo green "\n[+] The BotPanel Bot Has Been Successfully Installed"
log "phpMyAdmin administrative password: ${PHP_MYADMIN_PASS}"
log "Database Name: ${DB_NAME}"
log "Database User: ${DB_USER}"
log "Database Password: ${DB_PASS}"
