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


# Run a backup immediately on container startup
/backup.sh

# Continuous loop for daily backups
while true; do
	sleep_secs=$(calculate_sleep_seconds)
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] INFO: Next scheduled backup in ${sleep_secs} seconds" | tee -a "$LOG_FILE"
	sleep "$sleep_secs"
	/backup.sh
done
