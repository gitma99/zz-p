#!/bin/bash
set -euo pipefail

target_time_zone=${TZ:-"Asia/Tehran"}
LOG_FILE='/backups/backup.log'
readonly RETRY_DELAY_SECONDS=10
readonly FALLBACK_SLEEP_SECONDS=3600

mkdir -p /backups
touch "$LOG_FILE"

log() {
	local level=$1
	local message=$2
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] ${level}: ${message}" | tee -a "$LOG_FILE"
}

if [ -z "${BACKUP_MAX_RETRIES:-}" ]; then
	BACKUP_MAX_RETRIES=3
fi
if ! [[ "$BACKUP_MAX_RETRIES" =~ ^[0-9]+$ ]] || (( 10#$BACKUP_MAX_RETRIES < 1 )); then
	log "ERROR" "BACKUP_MAX_RETRIES must be a positive integer (>= 1)! Got: '${BACKUP_MAX_RETRIES:-}'"
	exit 1
fi

USE_INTERVAL=false
INTERVAL_SECONDS=0
if [ -n "${BACKUP_INTERVAL_MINUTES:-}" ]; then
	if ! [[ "$BACKUP_INTERVAL_MINUTES" =~ ^[0-9]+$ ]] || (( 10#$BACKUP_INTERVAL_MINUTES < 5 )); then
		log "ERROR" "BACKUP_INTERVAL_MINUTES must be an integer >= 5! Got: '${BACKUP_INTERVAL_MINUTES:-}'"
		exit 1
	fi
	USE_INTERVAL=true
	INTERVAL_SECONDS=$((10#$BACKUP_INTERVAL_MINUTES * 60))
fi

log "INFO" "=== ZZ Panel Backup Service Started ==="
log "INFO" "TZ: $target_time_zone"
if [ "$USE_INTERVAL" = true ]; then
	log "INFO" "Schedule: every ${BACKUP_INTERVAL_MINUTES} minutes (${INTERVAL_SECONDS} seconds)"
else
	log "INFO" "Schedule: daily at midnight ($target_time_zone)"
fi
log "INFO" "Max backup retries per run: $BACKUP_MAX_RETRIES (retry delay ${RETRY_DELAY_SECONDS}s, fallback ${FALLBACK_SLEEP_SECONDS}s)"

calculate_sleep_seconds() {
	local current_seconds tomorrow_midnight_seconds
	current_seconds=$(TZ="$target_time_zone" date +%s)
	tomorrow_midnight_seconds=$(TZ="$target_time_zone" date -d '00:00 tomorrow' +%s)
	echo $((tomorrow_midnight_seconds - current_seconds))
}

schedule_sleep_seconds() {
	if [ "$USE_INTERVAL" = true ]; then
		echo "$INTERVAL_SECONDS"
	else
		calculate_sleep_seconds
	fi
}


run_backup_with_retries() {
	local max_attempts="$BACKUP_MAX_RETRIES"
	local attempt=1
	local delay="$RETRY_DELAY_SECONDS"

	while [ "$attempt" -le "$max_attempts" ]; do
		log "INFO" "Running backup (attempt ${attempt}/${max_attempts})..."
		
		# We disable 'set -e' temporarily so the entrypoint script doesn't crash on failure
		set +e
		/backup.sh
		local status=$?
		set -e

		if [ "$status" -eq 0 ]; then
			log "INFO" "Backup completed successfully."
			return 0
		else
			log "WARN" "Backup failed with exit code ${status}."
		fi

		if [ "$attempt" -lt "$max_attempts" ]; then
			log "INFO" "Retrying in ${delay} seconds..."
			sleep "$delay"
		fi
		attempt=$((attempt + 1))
	done

	log "ERROR" "Backup failed after ${max_attempts} attempts. Will try again in ${FALLBACK_SLEEP_SECONDS} seconds."
	return 1
}

# Run a backup immediately on container startup
if ! run_backup_with_retries; then
	log "INFO" "Backup failed on startup. Sleeping for ${FALLBACK_SLEEP_SECONDS} seconds..."
	sleep "$FALLBACK_SLEEP_SECONDS"
fi

# Continuous loop for scheduled backups
while true; do
	sleep_secs=$(schedule_sleep_seconds)
	log "INFO" "Next scheduled backup in ${sleep_secs} seconds"
	sleep "$sleep_secs"
	
	if ! run_backup_with_retries; then
		log "INFO" "Scheduled backup failed. Postponing next attempt for ${FALLBACK_SLEEP_SECONDS} seconds..."
		sleep "$FALLBACK_SLEEP_SECONDS"
	fi
done
