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

	local response
	local http_code
	local body
	local curl_err
	curl_err=$(mktemp)
	response=$(curl -sS -w "\n%{http_code}" -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
		-d chat_id="${TELEGRAM_USER_ID}" \
		-d parse_mode=HTML \
		-d disable_web_page_preview=true \
		-d text="${payload}" 2> "$curl_err" || true)

	body=$(echo "$response" | head -n -1)
	http_code=$(echo "$response" | tail -n 1)
	local err_msg
	err_msg=$(cat "$curl_err")
	rm -f "$curl_err"

	if [ "$http_code" -ne 200 ]; then
		log "WARN" "Failed to send Telegram message. HTTP Code: ${http_code}. Error: ${err_msg}. Response: ${body}"
	fi
}

send_telegram_document() {
	local file_path=$1

	if [ -z "${TELEGRAM_BOT_TOKEN:-}" ] || [ -z "${TELEGRAM_USER_ID:-}" ]; then
		log "WARN" "Telegram is not configured, cannot send document"
		return 1
	fi

	log "INFO" "Sending Telegram document: '${file_path}'"

	# Simple cat pipe to curl bypasses curl file opening issues.
	# -v prints full connection logs so we can see exactly where it stops/fails.
	if cat "$file_path" | curl -v \
		-F "chat_id=${TELEGRAM_USER_ID}" \
		-F "document=@-;filename=$(basename "$file_path")" \
		"https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendDocument"; then
		log "INFO" "Telegram document sent successfully"
		
		# Send details as a separate message now
		local caption
		caption="Host: $(hostname)
File: $(basename "$file_path")"
		send_telegram_message "✅ Backup completed" "$caption"
		return 0
	else
		log "ERROR" "Telegram upload failed."
		return 1
	fi
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

# Set BACKUP_RETENTION_DAYS with default and validation
if [ -z "${BACKUP_RETENTION_DAYS:-}" ]; then
	log "WARN" "BACKUP_RETENTION_DAYS not set, defaulting to 14 days"
	BACKUP_RETENTION_DAYS=14
fi

# Validate BACKUP_RETENTION_DAYS is a positive integer
if ! [[ "$BACKUP_RETENTION_DAYS" =~ ^[0-9]+$ ]] || [ "$BACKUP_RETENTION_DAYS" -le 0 ]; then
	echo "ERROR: BACKUP_RETENTION_DAYS must be a positive integer!"
	exit 1
fi

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

# Upload to Telegram and clean up old backups on success
if [ -n "${TELEGRAM_BOT_TOKEN}" ] && [ -n "${TELEGRAM_USER_ID}" ]; then
	if send_telegram_document "$timestamped_archive"; then
		log "INFO" "Cleaning up zip backups older than ${BACKUP_RETENTION_DAYS} days..."
		# Find and delete zip backups matching pattern older than ${BACKUP_RETENTION_DAYS} days
		find /backups -name "zz-p-backup-*.zip" -type f -mtime +"${BACKUP_RETENTION_DAYS}" -print -delete || log "WARN" "Clean up of old backups failed or no old files found"
	fi
else
	log "INFO" "Telegram variables not fully set. Skipping upload."
fi
