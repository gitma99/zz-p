#!/bin/bash

# Exit immediately on error
set -euo pipefail
IFS=$'\n\t'

# -----------------------
# Defaults
# -----------------------
GITHUB_REPO_ADDRESS='https://github.com/gitma99/zz-p.git'
APP_DIR="/var/www/html/ZanborPanelBot"
BOT_CONFIG_JSON_PATH="${APP_DIR}/bot_config.json"
CERTBOT_WEBROOT_PATH="/var/www/html"
PHP_MYADMIN_PASS="zanborhipass"

INSTALLATION_PROOF_FILE="${APP_DIR}/index.php"


# CLI args (defaults)
DOMAIN=""
CERTBOT_EMAIL=""
BOT_TOKEN=""
CHAT_ID=""
MYSQL_ROOT_PASS=""    # if empty -> use sudo mysql
DB_USER=""
DB_PASS=""
RANDOM_DB_CREDS=false

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
        red)      printf "\e[91m${text}\e[0m\n";;
        green)    printf "\e[92m${text}\e[0m\n";;
        yellow)   printf "\e[93m${text}\e[0m\n";;
        blue)     printf "\e[94m${text}\e[0m\n";;
        magenta)  printf "\e[95m${text}\e[0m\n";;
        cyan)     printf "\e[96m${text}\e[0m\n";;
        *)          echo "${text}";;
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
  --db-username <dbuser>                    Database username (interactive fallback)
  --db-password <dbpass>                    Database password (interactive fallback)
  --random-db-username-password             Force generation of random DB username & password
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
# Pre-checks
# -----------------------
# Require root
if [[ "$(id -u)" -ne 0 ]]; then
    fatal "This script must be run as root (sudo)."
fi

# Require Ubuntu 20
. /etc/os-release
if [[ "$ID" != "ubuntu" || "$VERSION_ID" != 20.* ]]; then
    fatal "This script only supports Ubuntu 20.x."
fi
log "Running on Ubuntu $VERSION_ID"

if [ -e "$INSTALLATION_PROOF_FILE" ]; then
  fatal "Bot already installed. uninstall first"
  exit 0
fi

log "Installation started. Please wait..."


# -----------------------
# Install Packages
# -----------------------
log "Updating system packages..."
apt update -y && apt upgrade -y

log "Installing required packages..."
apt install -y dialog curl jq ufw git wget unzip pwgen sshpass \
    mysql-server apache2 libapache2-mod-php \
    php php-mbstring php-zip php-gd php-json php-curl php-soap php-ssh2 \
    libssh2-1-dev libssh2-1 phpmyadmin certbot python3-certbot-apache
# lamp-server^

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
        -h|--help) usage ;;
        *) echo "Unknown option: $1"; usage ;;
    esac
done

[[ -z "$DOMAIN" ]]         && read_input "Enter domain (without http/https)" "" DOMAIN
[[ -z "$CERTBOT_EMAIL" ]]  && read_input "Enter contact email for Let's Encrypt" "admin@example.com" CERTBOT_EMAIL
[[ -z "$BOT_TOKEN" ]]      && read_input "Enter Telegram bot token" "" BOT_TOKEN
[[ -z "$CHAT_ID" ]]        && read_input "Enter Telegram chat ID" "" CHAT_ID

rand_db_user=$(pwgen -A 8 1)
rand_db_pass=$(openssl rand -base64 8 | tr -dc 'a-zA-Z0-9' | head -c 10)

if $RANDOM_DB_CREDS; then
    DB_USER=$rand_db_user
    DB_PASS=$rand_db_pass
else
    [[ -z "$DB_USER" ]] && read_input "Enter DB username" "$rand_db_user" DB_USER
    [[ -z "$DB_PASS" ]] && read_input "Enter DB password" "$rand_db_pass" DB_PASS
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
systemctl restart apache2
systemctl enable --now mysql apache2

PHP_VERSION=$(php -v | head -n1 | awk '{print $2}' | cut -d '.' -f 1,2)
PHP_MODULE="php${PHP_VERSION}"

# ufw allow 'Apache Full'
# sudo systemctl restart apache2

# # Disable mpm_event if enabled
# if apache2ctl -M | grep -q 'mpm_event_module'; then
#     log "Disabling mpm_event module..."
#     a2dismod mpm_event
# else
#     log "mpm_event is not enabled. Skipping disable step."
# fi

# # Enable mpm_prefork
# log "Enabling mpm_prefork module..."
# a2enmod mpm_prefork

# # Enable PHP module
# log "Enabling Apache PHP module: ${PHP_MODULE}..."
# a2enmod "${PHP_MODULE}"

# # Restart Apache to apply changes
# log "Restarting Apache server..."
# systemctl restart apache2

# log "Apache has been configured to use mpm_prefork with PHP ${PHP_VERSION}."

# -----------------------
# Clone Bot Repository
# -----------------------
log "Cloning application repository..."
git clone "$GITHUB_REPO_ADDRESS" "$APP_DIR"
mkdir -p "${APP_DIR}/Logs" "${APP_DIR}/Temp"
chmod -R 777 "$APP_DIR"
# sudo chmod 777 $BOT_CONFIG_JSON_PATH
chmod 777 "$BOT_CONFIG_JSON_PATH" || true
ok "Application files installed at $APP_DIR"

# -----------------------
# SSL setup
# -----------------------
log "Setting up SSL for domain $DOMAIN..."
ufw allow 80
ufw allow 443
systemctl enable certbot.timer
certbot --apache --agree-tos --non-interactive \
    --email "$CERTBOT_EMAIL" -d "$DOMAIN" \
    || fatal "Certbot failed for ${DOMAIN} (ensure the domain points to this server and port 80 is reachable)."
ok "SSL certificate installed for $DOMAIN"

# ======================= Database Config ===========================
# read -p "[+] Enter OS ROOT passord: " ROOT_PASSWORD
# randdbname=$(openssl rand -base64 8 | tr -dc 'a-zA-Z0-9' | head -c 4)
# DB_NAME="BotPanel_${randdbname}"
DB_NAME="BotPanel_$(openssl rand -hex 2)"

escaped_dbname="$(sql_escape "${DB_NAME}")"
escaped_dbuser="$(sql_escape "${DB_USER}")"
escaped_dbpass="$(sql_escape "${DB_PASS}")"
SQL=$(cat <<SQL
CREATE DATABASE IF NOT EXISTS \`${escaped_dbname}\`;
CREATE USER IF NOT EXISTS '${escaped_dbuser}'@'%' IDENTIFIED WITH mysql_native_password BY '${escaped_dbpass}';
GRANT ALL PRIVILEGES ON \`${escaped_dbname}\`.* TO '${escaped_dbuser}'@'%';
FLUSH PRIVILEGES;
SQL
)
log "Creating database '${DB_NAME}' and user '${DB_USER}'..."
mysql -uroot ${MYSQL_ROOT_PASS:+-p$MYSQL_ROOT_PASS} -e "$SQL"
ok "Database and user created."

# sshpass -p $ROOT_PASSWORD mysql -u root -p -e "SET GLOBAL validate_password.policy = LOW;"
# sshpass -p $ROOT_PASSWORD mysql -u root -p -e "SET GLOBAL validate_password_policy = LOW;"

# -----------------------
# Configuration files
# -----------------------
config_address="${APP_DIR}/install/zanbor.install"
cat > "$config_address" <<EOF
{"development":"@ZanborPanel","install_location":"server","main_domin":"${DOMAIN}","token":"${BOT_TOKEN}","dev":"${CHAT_ID}","db_name":"${DB_NAME}","db_username":"${DB_USER}","db_password":"${DB_PASS}"}
EOF
# echo "{\"development\":\"@ZanborPanel\",\"install_location\":\"server\",\"main_domin\":\"${DOMAIN}\",\"token\":\"${BOT_TOKEN}\",\"dev\":\"${CHAT_ID}\",\"db_name\":\"${DB_NAME}\",\"db_username\":\"${rand_db_user}\",\"db_password\":\"${rand_db_pass}\"}" > /var/www/html/ZanborPanelBot/install/zanbor.install

config_template_path="${APP_DIR}/Templates/bot_config_template.json"
sed -e "s/\[\*DOMAIN\*\]/${DOMAIN}/g" \
    -e "s/\[\*TOKEN\*\]/${BOT_TOKEN}/g" \
    -e "s/\[\*DEV\*\]/${CHAT_ID}/g" \
    -e "s/\[\*DB-NAME\*\]/${DB_NAME}/g" \
    -e "s/\[\*DB-USER\*\]/${DB_USER}/g" \
    -e "s/\[\*DB-PASS\*\]/${DB_PASS}/g" \
    "$config_template_path" > "$BOT_CONFIG_JSON_PATH"

chmod 777 "$BOT_CONFIG_JSON_PATH"
chown -R www-data:www-data "$APP_DIR"

# -----------------------
# Final setup checks
# -----------------------
# curl process
colorized_echo blue "Database Status:"
curl -s "https://${DOMAIN}/ZanborPanelBot/sql/sql.php?db_password=${DB_PASS}&db_name=${DB_NAME}&db_username=${DB_USER}"

colorized_echo blue "\n\nSet Webhook Status:"
curl -F "url=https://${DOMAIN}/ZanborPanelBot/index.php" "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook"

TEXT_MESSAGE="✅ Bot has been successfully installed!"
curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
    -d chat_id="${CHAT_ID}" -d text="${TEXT_MESSAGE}"

colorized_echo green "[+] The BotPanel Bot Has Been Successfully Installed"
