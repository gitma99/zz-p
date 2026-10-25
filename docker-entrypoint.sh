#!/bin/bash
set -e

# Validate required environment variables
if [ -z "$BOT_TOKEN" ] || [ -z "$CHAT_ID" ] || [ -z "$DOMAIN" ] || [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
    echo "ERROR: Missing required environment variables. Please check BOT_TOKEN, CHAT_ID, DOMAIN, DB_NAME, DB_USER, and DB_PASS."
    exit 1
fi

# Set default DB_HOST if not provided
export DB_HOST="${DB_HOST:-mysql}"

# 1. Generate bot_config.json from template
TEMPLATE_PATH="/var/www/html/ZZPanelBot/Templates/bot_config_template.json"
CONFIG_PATH="/var/www/html/ZZPanelBot/bot_config.json"

if [ -f "$TEMPLATE_PATH" ]; then
    echo "Generating bot_config.json..."
    jq --arg dom "$DOMAIN" \
       --arg tok "$BOT_TOKEN" \
       --arg dev "$CHAT_ID" \
       --arg dbn "$DB_NAME" \
       --arg dbu "$DB_USER" \
       --arg dbp "$DB_PASS" \
       --arg dbh "$DB_HOST" \
       '.main_domin = $dom | .token = $tok | .dev = $dev | .database.db_name = $dbn | .database.db_username = $dbu | .database.db_password = $dbp | .database.db_host = $dbh' \
       "$TEMPLATE_PATH" > "$CONFIG_PATH"
else
    echo "WARNING: Config template not found at $TEMPLATE_PATH"
fi

# 2. Ensure directories exist
mkdir -p /var/www/html/ZZPanelBot/logs /var/www/html/ZZPanelBot/temp

# 3. Set ownership and permissions
chown -R www-data:www-data /var/www/html/ZZPanelBot
chmod -R 770 /var/www/html/ZZPanelBot/temp /var/www/html/ZZPanelBot/logs

# 4. Wait for MySQL to become ready
echo "Waiting for MySQL database connection..."
until php -r "
  \$db_host = getenv('DB_HOST') ?: 'localhost';
  \$mysqli = @new mysqli(\$db_host, '$DB_USER', '$DB_PASS', '$DB_NAME');
  if (\$mysqli->connect_error) {
    exit(1);
  }
" 2>/dev/null; do
  echo "Database is not ready yet, retrying in 2 seconds..."
  sleep 2
done
echo "MySQL is ready!"

# 5. Run database schema initialization
echo "Initializing database schema..."
php -r '
$_GET["db_username"] = $argv[1];
$_GET["db_password"] = $argv[2];
$_GET["db_name"] = $argv[3];
include "/var/www/html/ZZPanelBot/sql/sql.php";
' -- "$DB_USER" "$DB_PASS" "$DB_NAME"

# 6. Run versioned schema migrations
echo "Running versioned schema migrations..."
php "/var/www/html/ZZPanelBot/tools/run-migrations.php"

# 7. Set Telegram Webhook
echo "Configuring Telegram Webhook..."
WEBHOOK_URL="https://${DOMAIN}/ZZPanelBot/index.php"
curl -s -F "url=${WEBHOOK_URL}" "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" || echo "WARNING: Failed to set Telegram Webhook"

if [ "$1" = "worker" ]; then
    echo "Starting Worker..."
    exec php /var/www/html/ZZPanelBot/workers/task_worker.php
fi

# 8. Send startup notification
echo "Sending startup notification..."
TEXT_MESSAGE="✅ ربات شما با موفقیت راه اندازی/ریستارت شد."$'\n\n'"#️⃣ اطلاعات ربات :"$'\n\n'"▫️admin: <code>${CHAT_ID}</code>"$'\n'"▫️domain: <code>${DOMAIN}</code>"$'\n'"▫️db_username: <code>${DB_USER}</code>"
curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
    -d chat_id="${CHAT_ID}" -d text="${TEXT_MESSAGE}" -d parse_mode="html" || echo "WARNING: Failed to send Telegram startup notification"

# 9. Start PHP-FPM
echo "Starting PHP-FPM..."
service php7.4-fpm start

# 10. Start Apache in foreground
echo "Starting Apache2..."
# Clear pid file if exists
rm -f /var/run/apache2/apache2.pid
exec apache2ctl -D FOREGROUND
