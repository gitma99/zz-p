#!/bin/bash
set -euo pipefail
IFS=$'\n\t'

# === define variables
LOG_FILE='/backups/backup.log'

log() {
	local level=$1
	local message=$2
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] ${level}: ${message}" | tee -a "$LOG_FILE"
}

handle_error() {
	local err_msg=${1:-"Unknown error"}
	local line_no=${2:-"?"}
	log "ERROR" "Restore failed on line ${line_no}: ${err_msg}"
	exit 1
}

trap 'handle_error "${BASH_COMMAND:-}" "${LINENO:-?}"' ERR

# Validate required variables
for var in DB_HOST DB_NAME DB_USER DB_PASS; do
	if [ -z "${!var:-}" ]; then
		echo "ERROR: Environment variable $var is not set!"
		exit 1
	fi
done

if [ "$#" -lt 1 ]; then
	echo "Usage: $0 <path_to_backup_zip>"
	exit 1
fi

BACKUP_ZIP="$1"

if [ ! -f "$BACKUP_ZIP" ]; then
	echo "ERROR: Backup file '$BACKUP_ZIP' not found!"
	exit 1
fi

log "INFO" "Starting restore from ${BACKUP_ZIP}"

# Create temporary extraction folder
EXTRACT_DIR="/tmp/restore_extract_$(date +%s)"
mkdir -p "$EXTRACT_DIR"
log "DEBUG" "Created extraction dir: ${EXTRACT_DIR}"

# Extract zip
log "INFO" "Extracting backup archive"
unzip -q "$BACKUP_ZIP" -d "$EXTRACT_DIR"

# Find SQL file
SQL_FILE=""
if [ -f "$EXTRACT_DIR/root/zz-p-sql.sql" ]; then
	SQL_FILE="$EXTRACT_DIR/root/zz-p-sql.sql"
elif [ -f "$EXTRACT_DIR/zz-p-sql.sql" ]; then
	SQL_FILE="$EXTRACT_DIR/zz-p-sql.sql"
else
	SQL_FILE=$(find "$EXTRACT_DIR" -name "*.sql" -print -quit)
fi

if [ -z "$SQL_FILE" ] || [ ! -f "$SQL_FILE" ]; then
	rm -rf "$EXTRACT_DIR"
	handle_error "No SQL backup file found in archive" "$LINENO"
fi
log "INFO" "Found SQL file: ${SQL_FILE}"

# Import database
log "INFO" "Importing database into ${DB_NAME} on ${DB_HOST}"
start=$(date +%s)
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_FILE"
log "INFO" "Database import completed in $(( $(date +%s) - start ))s"

# Run migrations
log "INFO" "Running versioned schema migrations"
start=$(date +%s)
cd /var/www/html/ZZPanelBot && php tools/run-migrations.php
log "INFO" "Migrations completed in $(( $(date +%s) - start ))s"

# Cleanup
rm -rf "$EXTRACT_DIR"
log "DEBUG" "Cleaned up extraction dir"

log "INFO" "Restore completed successfully"
log "INFO" "NOTE: If you changed bot config env vars, run 'docker compose down && docker compose up -d'"