#!/bin/bash
set -euo pipefail

# Determine directories
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$SCRIPT_DIR/.."
BACKUPS_DIR="$PROJECT_ROOT/docker/backups"

colorized_echo() {
	local color=$1
	local text=$2
	case $color in
		"red")     printf "\e[91m%s\e[0m\n" "$text";;
		"green")   printf "\e[92m%s\e[0m\n" "$text";;
		"yellow")  printf "\e[93m%s\e[0m\n" "$text";;
		"blue")    printf "\e[94m%s\e[0m\n" "$text";;
		"magenta") printf "\e[95m%s\e[0m\n" "$text";;
		"cyan")    printf "\e[96m%s\e[0m\n" "$text";;
		*)         echo "$text";;
	esac
}

# Check if docker is running
if ! docker info > /dev/null 2>&1; then
	colorized_echo red "ERROR: Docker daemon is not running or current user has no permissions!"
	exit 1
fi

# Check if zzpanel_backup is running
if ! docker ps --format '{{.Names}}' | grep -q "^zzpanel_backup$"; then
	colorized_echo yellow "WARNING: 'zzpanel_backup' container is not running."
	colorized_echo yellow "Starting the backup service first..."
	docker compose -f "$PROJECT_ROOT/docker-compose.yml" up -d backup
	sleep 2
fi

# Select action
colorized_echo cyan "Select your action:"
options=("Backup" "Restore" "Exit")
select opt in "${options[@]}"; do
	case $opt in
		"Backup")
			echo ""
			read -p "Are you sure you want to trigger a manual backup? [y/n] : " answer
			if [[ "$answer" =~ ^[Yy]$ ]]; then
				colorized_echo green "Triggering backup inside the container..."
				docker exec -it zzpanel_backup /backup.sh
				colorized_echo green "Backup completed."
			else
				colorized_echo red "Backup Canceled!"
			fi
			break
			;;
		"Restore")
			echo ""
			# Check if there are any backup files
			mkdir -p "$BACKUPS_DIR"
			
			# Find zip files, handle spaces or empty results carefully
			files=()
			while IFS= read -r -d '' file; do
				files+=("$file")
			done < <(find "$BACKUPS_DIR" -maxdepth 1 -name "*.zip" -print0)

			if [ ${#files[@]} -eq 0 ]; then
				colorized_echo red "No backup ZIP files found in $BACKUPS_DIR!"
				break
			fi

			colorized_echo cyan "Select backup file to restore:"
			basenames=()
			for f in "${files[@]}"; do
				basenames+=("$(basename "$f")")
			done
			
			select file_opt in "${basenames[@]}" "Cancel"; do
				if [ "$file_opt" == "Cancel" ] || [ -z "$file_opt" ]; then
					colorized_echo red "Restore Canceled!"
					break
				fi
				
				read -p "Are you sure you want to restore from '$file_opt'? THIS WILL OVERWRITE THE CURRENT DATABASE! [y/n] : " confirm
				if [[ "$confirm" =~ ^[Yy]$ ]]; then
					colorized_echo green "Preparing restore..."
					
					# Copy archive into backup container
					docker cp "$BACKUPS_DIR/$file_opt" zzpanel_backup:/tmp/zz-p-restore-temp.zip
					
					# Run restore script inside container
					docker exec -it zzpanel_backup /restore.sh /tmp/zz-p-restore-temp.zip
					
					# Clean up copied file inside container
					docker exec zzpanel_backup rm -f /tmp/zz-p-restore-temp.zip
					
					colorized_echo green "Restore finished."
					
					# Prompt to restart other services
					read -p "Do you want to restart the app and webserver containers to ensure fresh states? [y/n] : " restart_confirm
					if [[ "$restart_confirm" =~ ^[Yy]$ ]]; then
						colorized_echo green "Restarting app stack..."
						docker compose -f "$PROJECT_ROOT/docker-compose.yml" restart app nginx
					fi
				else
					colorized_echo red "Restore Canceled!"
				fi
				break
			done
			break
			;;
		"Exit")
			colorized_echo green "Exited!"
			break
			;;
		*)
			colorized_echo red "Invalid option!"
			break
			;;
	esac
done
