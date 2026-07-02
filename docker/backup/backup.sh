#!/bin/bash
set -euo pipefail
IFS=$'\n\t'

# === define variables
target_time_zone=${TZ:-"Asia/Tehran"}
LOG_FILE='/backups/backup.log'

TELEGRAM_BOT_TOKEN="${BACKUP_TELEGRAM_TOKEN:-$BOT_TOKEN}"
TELEGRAM_USER_ID="${BACKUP_CHAT_ID:-$CHAT_ID}"

timestamp() {
	TZ="$target_time_zone" date '+%Y-%m-%d %H:%M:%S %Z'
}

log() {
	local level=$1
	local message=$2
	local ts
	ts=$(timestamp)
	echo "[${ts}] ${level}: ${message}" | tee -a "$LOG_FILE"
}

send_telegram_message() {
	local title=$1
	local text=$2

	if [ -z "${TELEGRAM_BOT_TOKEN:-}" ] || [ -z "${TELEGRAM_USER_ID:-}" ]; then
		log "WARN" "Telegram is not configured, cannot send alert"
		return
	fi

	local payload
	payload="<b>${title}</b>\nTime: $(timestamp)\n${text}"

	curl -s --fail -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
		-d chat_id="${TELEGRAM_USER_ID}" \
		-d parse_mode=HTML \
		-d disable_web_page_preview=true \
		-d text="${payload}" > /dev/null 2>&1 || {
			log "WARN" "Failed to send Telegram notification"
		}
}

send_telegram_document() {
	local file_path=$1
	local max_attempts=3
	local attempt=1


	if [ -z "${TELEGRAM_BOT_TOKEN:-}" ] || [ -z "${TELEGRAM_USER_ID:-}" ]; then
		log "WARN" "Telegram is not configured, cannot send document"
		return 1
	fi

	local caption
	caption="<b>✅ Backup completed</b>\nHost: $(hostname)\nTime: $(timestamp)\nFile: $(basename "$file_path")"

	while [ "$attempt" -le "$max_attempts" ]; do
		log "INFO" "Sending Telegram document (attempt ${attempt}/${max_attempts})"

		if curl -s --fail -X POST \
			-F "chat_id=${TELEGRAM_USER_ID}" \
			-F "document=@${file_path}" \
			-F "caption=${caption}" \
			-F "parse_mode=HTML" \
			"https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendDocument" > /dev/null 2>&1; then
			log "INFO" "Telegram document sent successfully"
			return 0
		fi

		if [ "$attempt" -lt "$max_attempts" ]; then
			log "WARN" "Telegram document send failed, retrying in 10 seconds"
			sleep 10
		fi

		attempt=$((attempt + 1))
	done

	send_telegram_message "❌ Backup upload failed" "Failed to send backup document after ${max_attempts} attempts."
	return 1
}

handle_error() {
	local err_msg=${1:-"Unknown error"}
	local line_no=${2:-"?"}
	local full_msg="Error on line ${line_no}: ${err_msg}"
	log "ERROR" "$full_msg"
	send_telegram_message "⚠️ Backup error" "$full_msg"
	exit 1
}

trap 'handle_error "${BASH_COMMAND:-}" "${LINENO:-?}"' ERR
trap 'handle_error "Backup process interrupted" "${LINENO:-?}"' INT TERM

# Validate required variables
for var in DB_HOST DB_NAME DB_USER DB_PASS BOT_TOKEN CHAT_ID DOMAIN; do
	if [ -z "${!var:-}" ]; then
		echo "ERROR: Environment variable $var is not set!"
		exit 1
	fi
done

# Prepare directory
mkdir -p "$(dirname "$LOG_FILE")"

log "INFO" "Starting backup process..."

# Prepare paths for zip structure matching legacy backups
mkdir -p /var/www/html/ZZPanelBot
mkdir -p /root

# Generate bot_config.json inside directory structure matching legacy bot config path
jq -n \
	--arg dom "$DOMAIN" \
	--arg tok "$BOT_TOKEN" \
	--arg dev "$CHAT_ID" \
	--arg dbn "$DB_NAME" \
	--arg dbu "$DB_USER" \
	--arg dbp "$DB_PASS" \
	--arg dbh "$DB_HOST" \
	'{main_domin: $dom, token: $tok, dev: $dev, database: {db_name: $dbn, db_username: $dbu, db_password: $dbp, db_host: $dbh}}' > /var/www/html/ZZPanelBot/bot_config.json

# Dump DB
mysqldump --no-tablespaces -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > /root/zz-p-sql.sql

if [ ! -f "/root/zz-p-sql.sql" ] || [ ! -s "/root/zz-p-sql.sql" ]; then
	handle_error "Database dump was not created or is empty" "$LINENO"
fi

# Create archive
archive_file="/backups/zz-p-backup.zip"
rm -f "$archive_file"
zip -r "$archive_file" /var/www/html/ZZPanelBot/bot_config.json /root/zz-p-sql.sql

if [ ! -f "$archive_file" ]; then
	handle_error "Backup zip was not created" "$LINENO"
fi

# Keep a timestamped copy for history
timestamped_archive="/backups/zz-p-backup-$(date +%Y%m%d_%H%M%S).zip"
cp "$archive_file" "$timestamped_archive"

# Cleanup temporary files used for matching legacy layout
rm -f /var/www/html/ZZPanelBot/bot_config.json
rm -f /root/zz-p-sql.sql

log "INFO" "Backup successfully created: ${timestamped_archive}"

# Upload to Telegram
if [ -n "${TELEGRAM_BOT_TOKEN}" ] && [ -n "${TELEGRAM_USER_ID}" ]; then
	send_telegram_document "$timestamped_archive"
else
	log "INFO" "Telegram variables not fully set. Skipping upload."
fi
