#!/bin/bash
set -euo pipefail
IFS=$'\n\t'

# === define variables
target_time_zone='Asia/Tehran'
CONFIG_JSON_PATH='/root/backup_service_config.json'
APP_DIR="/var/www/html/ZZPanelBot"
ZZ_BOT_CONFIG_JSON_PATH="${APP_DIR}/bot_config.json"
LOG_FILE='/root/zz-p-backup.log'
readonly RETRY_DELAY_SECONDS=10
readonly FALLBACK_SLEEP_SECONDS=3600
readonly SEND_RETRIES=3

TELEGRAM_BOT_TOKEN=''
TELEGRAM_USER_ID=''

timestamp() {
    date '+%Y-%m-%d %H:%M:%S %Z'
}

colorized_echo() {
    local color=$1
    local text=$2
    case $color in
        "red")
            printf "\e[91m%s\e[0m\n" "$text";;
        "green")
            printf "\e[92m%s\e[0m\n" "$text";;
        "yellow")
            printf "\e[93m%s\e[0m\n" "$text";;
        "blue")
            printf "\e[94m%s\e[0m\n" "$text";;
        "magenta")
            printf "\e[95m%s\e[0m\n" "$text";;
        "cyan")
            printf "\e[96m%s\e[0m\n" "$text";;
        *)
            echo "$text";;
    esac
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
    local max_attempts="$SEND_RETRIES"
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
            log "WARN" "Telegram document send failed, retrying in ${RETRY_DELAY_SECONDS} seconds"
            sleep "$RETRY_DELAY_SECONDS"
        fi

        attempt=$((attempt + 1))
    done

    send_telegram_message "❌ Backup upload failed" "Failed to send backup document after ${max_attempts} attempts."
    return 1
}

handle_error() {
    local err_msg=${1:-"Unknown error"}
    local line_no=${2:-"?"}
    local ts
    ts=$(timestamp)
    local full_msg="Error on line ${line_no}: ${err_msg}"
    log "ERROR" "$full_msg"
    send_telegram_message "⚠️ Backup error" "$full_msg"
    exit 1
}

trap 'handle_error "${BASH_COMMAND}" "$LINENO"' ERR
trap 'handle_error "Script interrupted" "${LINENO:-?}"' INT TERM

validate_schedule_env() {
	if [ -z "${BACKUP_MAX_RETRIES:-}" ]; then
		BACKUP_MAX_RETRIES=3
	fi
	if ! [[ "$BACKUP_MAX_RETRIES" =~ ^[0-9]+$ ]] || (( 10#$BACKUP_MAX_RETRIES < 1 )); then
		echo "[$(timestamp)] ERROR: BACKUP_MAX_RETRIES must be a positive integer (>= 1)! Got: '${BACKUP_MAX_RETRIES:-}'" | tee -a "$LOG_FILE"
		exit 1
	fi

	USE_INTERVAL=false
	INTERVAL_SECONDS=0
	if [ -n "${BACKUP_INTERVAL_MINUTES:-}" ]; then
		if ! [[ "$BACKUP_INTERVAL_MINUTES" =~ ^[0-9]+$ ]] || (( 10#$BACKUP_INTERVAL_MINUTES < 5 )); then
			echo "[$(timestamp)] ERROR: BACKUP_INTERVAL_MINUTES must be an integer >= 5! Got: '${BACKUP_INTERVAL_MINUTES:-}'" | tee -a "$LOG_FILE"
			exit 1
		fi
		USE_INTERVAL=true
		INTERVAL_SECONDS=$((10#$BACKUP_INTERVAL_MINUTES * 60))
	fi
}

# Fail fast on bad schedule env before traps/heavy init can mask or double-report it.
trap - ERR
validate_schedule_env
trap 'handle_error "${BASH_COMMAND}" "$LINENO"' ERR

check_required_commands() {
    local cmds=(jq mysqldump zip curl date)
    for cmd in "${cmds[@]}"; do
        if ! command -v "$cmd" > /dev/null 2>&1; then
            handle_error "Required command '$cmd' not found"
        fi
    done
}

load_telegram_config() {
    if [ ! -f "$CONFIG_JSON_PATH" ]; then
        handle_error "Telegram config file missing: ${CONFIG_JSON_PATH}"
    fi

    TELEGRAM_BOT_TOKEN=$(jq -r '.token // empty' < "$CONFIG_JSON_PATH")
    TELEGRAM_USER_ID=$(jq -r '.chat_id // empty' < "$CONFIG_JSON_PATH")

    if [ -z "$TELEGRAM_BOT_TOKEN" ] || [ -z "$TELEGRAM_USER_ID" ]; then
        handle_error "Telegram token or chat_id missing in ${CONFIG_JSON_PATH}"
    fi
}

validate_bot_config() {
    if [ ! -f "$ZZ_BOT_CONFIG_JSON_PATH" ]; then
        handle_error "Bot config file missing: ${ZZ_BOT_CONFIG_JSON_PATH}"
    fi
    if [ ! -s "$ZZ_BOT_CONFIG_JSON_PATH" ]; then
        handle_error "Bot config file is empty: ${ZZ_BOT_CONFIG_JSON_PATH}"
    fi
}

parse_db_credentials() {
    DB_NAME=$(jq -r '.database.db_name // empty' < "$ZZ_BOT_CONFIG_JSON_PATH")
    DB_USER=$(jq -r '.database.db_username // empty' < "$ZZ_BOT_CONFIG_JSON_PATH")
    DB_PASSWORD=$(jq -r '.database.db_password // empty' < "$ZZ_BOT_CONFIG_JSON_PATH")

    if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASSWORD" ]; then
        handle_error "Database credentials missing in ${ZZ_BOT_CONFIG_JSON_PATH}"
    fi
}

prepare_environment() {
    mkdir -p "$(dirname "$LOG_FILE")"
    touch "$LOG_FILE"
    chmod 640 "$LOG_FILE"
}

backup_database() {
    local dump_file='/root/zz-p-sql.sql'
    local archive_file='/root/zz-p-backup.zip'

    rm -f "$dump_file" "$archive_file"

    log "INFO" "Starting database backup"
    mysqldump --no-tablespaces \
        -h 127.0.0.1 -P 3306 \
        -u "$DB_USER" -p"$DB_PASSWORD" \
        "$DB_NAME" > "$dump_file"

    if [ ! -f "$dump_file" ]; then
        handle_error "Database dump file was not created"
    fi

    zip -j "$archive_file" "$ZZ_BOT_CONFIG_JSON_PATH" "$dump_file"

    if [ ! -f "$archive_file" ]; then
        handle_error "Backup archive was not created"
    fi

    log "INFO" "Backup created: ${archive_file}"
}

send_backup() {
    local archive_file='/root/zz-p-backup.zip'
    log "INFO" "Sending backup to Telegram"
    if ! send_telegram_document "$archive_file"; then
        handle_error "Failed to send backup document after retries"
    fi
}

calculate_sleep_seconds() {
    local current_seconds tomorrow_midnight_seconds
    current_seconds=$(TZ="$target_time_zone" date +%s)
    tomorrow_midnight_seconds=$(TZ="$target_time_zone" date -d '00:00 tomorrow' +%s)
    echo $((tomorrow_midnight_seconds - current_seconds))
}

check_required_commands
load_telegram_config
validate_bot_config
parse_db_credentials
prepare_environment

if [ "$USE_INTERVAL" = true ]; then
	log "INFO" "Schedule: every ${BACKUP_INTERVAL_MINUTES} minutes (${INTERVAL_SECONDS} seconds)"
else
	log "INFO" "Schedule: daily at midnight ($target_time_zone)"
fi
log "INFO" "Max backup retries per run: $BACKUP_MAX_RETRIES (retry delay ${RETRY_DELAY_SECONDS}s, fallback ${FALLBACK_SLEEP_SECONDS}s)"
log "INFO" "Telegram upload retries per backup: $SEND_RETRIES (delay ${RETRY_DELAY_SECONDS}s)"

run_backup_with_retries() {
	local attempt=1
	local delay="$RETRY_DELAY_SECONDS"

	while [ "$attempt" -le "$BACKUP_MAX_RETRIES" ]; do
		log "INFO" "Running backup (attempt ${attempt}/${BACKUP_MAX_RETRIES})..."

		set +e
		(trap - ERR; backup_database; send_backup)
		local status=$?
		set -e

		if [ "$status" -eq 0 ]; then
			log "INFO" "Backup completed successfully."
			return 0
		else
			log "WARN" "Backup failed with exit code ${status}."
		fi

		if [ "$attempt" -lt "$BACKUP_MAX_RETRIES" ]; then
			log "INFO" "Retrying in ${delay} seconds..."
			sleep "$delay"
		fi
		attempt=$((attempt + 1))
	done

	log "ERROR" "Backup failed after ${BACKUP_MAX_RETRIES} attempts. Will try again in ${FALLBACK_SLEEP_SECONDS} seconds."
	return 1
}

schedule_sleep_seconds() {
	if [ "$USE_INTERVAL" = true ]; then
		echo "$INTERVAL_SECONDS"
	else
		calculate_sleep_seconds
	fi
}

if ! run_backup_with_retries; then
	log "INFO" "Backup failed on startup. Sleeping for ${FALLBACK_SLEEP_SECONDS} seconds..."
	sleep "$FALLBACK_SLEEP_SECONDS"
fi

while true; do
	if [ ! -d "$APP_DIR" ]; then
		handle_error "The BotPanel folder was not found for the backup process, install the bot first!"
	fi

	if ! run_backup_with_retries; then
		log "INFO" "Scheduled backup failed. Postponing next attempt for ${FALLBACK_SLEEP_SECONDS} seconds..."
		sleep "$FALLBACK_SLEEP_SECONDS"
		continue
	fi

	time_remaining=$(schedule_sleep_seconds)
	log "INFO" "Next backup in ${time_remaining} seconds"
	sleep "$time_remaining"
done
