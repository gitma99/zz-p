#!/bin/bash
set -euo pipefail

target_time_zone=${TZ:-"Asia/Tehran"}
LOG_FILE='/backups/backup.log'

mkdir -p /backups
touch "$LOG_FILE"

echo "=== ZZ Panel Backup Service Started ===" | tee -a "$LOG_FILE"
echo "TZ: $target_time_zone" | tee -a "$LOG_FILE"

calculate_sleep_seconds() {
	local current_seconds tomorrow_midnight_seconds
	current_seconds=$(TZ="$target_time_zone" date +%s)
	tomorrow_midnight_seconds=$(TZ="$target_time_zone" date -d '00:00 tomorrow' +%s)
	echo $((tomorrow_midnight_seconds - current_seconds))
}


run_backup_with_retries() {
	local max_attempts=3
	local attempt=1
	local delay=10

	while [ "$attempt" -le "$max_attempts" ]; do
		echo "[$(date '+%Y-%m-%d %H:%M:%S')] INFO: Running backup (attempt ${attempt}/${max_attempts})..." | tee -a "$LOG_FILE"
		
		# We disable 'set -e' temporarily so the entrypoint script doesn't crash on failure
		set +e
		/backup.sh
		local status=$?
		set -e

		if [ "$status" -eq 0 ]; then
			echo "[$(date '+%Y-%m-%d %H:%M:%S')] INFO: Backup completed successfully." | tee -a "$LOG_FILE"
			return 0
		else
			echo "[$(date '+%Y-%m-%d %H:%M:%S')] WARN: Backup failed with exit code ${status}." | tee -a "$LOG_FILE"
		fi

		if [ "$attempt" -lt "$max_attempts" ]; then
			echo "[$(date '+%Y-%m-%d %H:%M:%S')] INFO: Retrying in ${delay} seconds..." | tee -a "$LOG_FILE"
			sleep "$delay"
		fi
		attempt=$((attempt + 1))
	done

	echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: Backup failed after ${max_attempts} attempts. Will try again in 1 hour." | tee -a "$LOG_FILE"
	return 1
}

# Run a backup immediately on container startup
if ! run_backup_with_retries; then
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] INFO: Backup failed on startup. Sleeping for 1 hour..." | tee -a "$LOG_FILE"
	sleep 3600
fi

# Continuous loop for daily backups
while true; do
	sleep_secs=$(calculate_sleep_seconds)
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] INFO: Next scheduled backup in ${sleep_secs} seconds" | tee -a "$LOG_FILE"
	sleep "$sleep_secs"
	
	if ! run_backup_with_retries; then
		echo "[$(date '+%Y-%m-%d %H:%M:%S')] INFO: Scheduled backup failed. Postponing next attempt for 1 hour..." | tee -a "$LOG_FILE"
		sleep 3600
	fi
done
