#!/bin/bash
# === define global variables ===
APP_DIR="/var/www/html/ZZPanelBot"
SCRIPT_PATH="${APP_DIR}/Scripts/telegram_schedual_backup.sh"
LOG_PATH="${APP_DIR}/logs/backup.log"
SERVICE_FILE_TEMPLATE_PATH="${APP_DIR}/Templates/zz-backup.service"
SERVICE_FILE_NAME="zz-backup.service"
TELEGRAM_CONFIG_TEMPLATE="${APP_DIR}/Templates/backup_service_config.json"
TELEGRAM_CONFIG_PATH="/root/backup_service_config.json"

# === colorized echo ===
colorized_echo() {
    local color=$1
    local text=$2
    case $color in
        "red")     printf "\e[91m${text}\e[0m\n";;
        "green")   printf "\e[92m${text}\e[0m\n";;
        "yellow")  printf "\e[93m${text}\e[0m\n";;
        "blue")    printf "\e[94m${text}\e[0m\n";;
        "magenta") printf "\e[95m${text}\e[0m\n";;
        "cyan")    printf "\e[96m${text}\e[0m\n";;
        *)         echo "${text}";;
    esac
}

# === error handler ===
handle_error() {
    local ERROR_MSG=$1
    local LINE_NO=$2
    colorized_echo red "[$(date +"%Y-%m-%d %H:%M:%S")] ERROR: Error on line $LINE_NO: $ERROR_MSG"
    exit 1
    # You can add more error handling logic here if needed
}

# === trap errors ===
trap 'handle_error "$BASH_COMMAND" $LINENO' ERR

# === ensure root access ===
if [[ $EUID -ne 0 ]]; then
    handle_error "Run script as root or with 'sudo'!" $LINENO
fi

# === check required files ===
for file in "$SCRIPT_PATH" "$SERVICE_FILE_TEMPLATE_PATH" "$TELEGRAM_CONFIG_TEMPLATE"; do
    if [ ! -f "$file" ]; then
        handle_error "File $file does not exist" $LINENO
    fi
done

# === user options ===
colorized_echo green "\n[+] - Please wait for a few seconds!"
echo " "
actions=(
    "Install/reinstall/update schedule backup job"
    "Uninstall schedule backup job"
    "Start service"
    "Stop service"
    "Disable service Auto start"
    "Enable service Auto start"
    "Show service status"
    "Show service logs"
    "Show script live logs"
    "Exit"
)
select opt in "${actions[@]}"; do
    case $opt in
        "Install/reinstall/update schedule backup job")
            colorized_echo cyan "Enter 'Telegram Token':"
            read TOKEN
            colorized_echo cyan "Enter target Telegram 'chat id' number:"
            read CHAT_ID

            # install dependencies
            apt install zip curl -qqq -y

            # update Telegram config
            TMP_CONFIG="${TELEGRAM_CONFIG_TEMPLATE}.tmp"
            sed -e "s/\[\*TOKEN\*\]/${TOKEN}/g" -e "s/\[\*CHAT\*\]/${CHAT_ID}/g" "$TELEGRAM_CONFIG_TEMPLATE" > "$TMP_CONFIG"
            mv -f "$TMP_CONFIG" "$TELEGRAM_CONFIG_PATH"

            # create systemd service file
            TMP_SERVICE="/tmp/$SERVICE_FILE_NAME"
            cp -f "$SERVICE_FILE_TEMPLATE_PATH" "$TMP_SERVICE"
            sed -i 's#{{BACKUP-SCRIPT-PATH}}#'"$SCRIPT_PATH"'#g' "$TMP_SERVICE"
            sed -i 's#{{LOG-PATH}}#'"$LOG_PATH"'#g' "$TMP_SERVICE"
            cp -f "$TMP_SERVICE" "/etc/systemd/system/$SERVICE_FILE_NAME"
            rm -f "$TMP_SERVICE"
            chmod 644 "/etc/systemd/system/$SERVICE_FILE_NAME"
            chmod 755 "$SCRIPT_PATH"
            colorized_echo green "INFO: Successfully created $SERVICE_FILE_NAME."

            # enable and start service on boot
            systemctl daemon-reload
            systemctl enable --now "$SERVICE_FILE_NAME"
            systemctl restart "$SERVICE_FILE_NAME"
            colorized_echo green "INFO: Successfully installed service, enabled it for boot, and started it."
            journalctl -u "$SERVICE_FILE_NAME" -f
            break
            ;;
        "Uninstall schedule backup job")
            systemctl disable --now "$SERVICE_FILE_NAME" || true
            rm -f "/etc/systemd/system/$SERVICE_FILE_NAME"
            systemctl daemon-reload
            colorized_echo green "INFO: Successfully uninstalled service."
            break
            ;;
        "Start service")
            systemctl restart "$SERVICE_FILE_NAME"
            colorized_echo green "INFO: Successfully started service."
            break
            ;;
        "Stop service")
            systemctl stop "$SERVICE_FILE_NAME"
            colorized_echo green "INFO: Successfully stopped service."
            break
            ;;
        "Disable service Auto start")
            systemctl disable "$SERVICE_FILE_NAME"
            colorized_echo green "INFO: Successfully disabled auto start service."
            break
            ;;
        "Enable service Auto start")
            systemctl enable "$SERVICE_FILE_NAME"
            colorized_echo green "INFO: Successfully enabled auto start Service."
            break
            ;;
        "Show service status")
            systemctl status "$SERVICE_FILE_NAME"
            exit 0
            ;;
        "Show service logs")
            journalctl -u "$SERVICE_FILE_NAME" -f
            exit 0
            ;;
        "Show script live logs")
            tail -f "$LOG_PATH"
            exit 0
            ;;
        "Exit")
            echo -e "\n"
            colorized_echo green "INFO: Exit Successful!"
            echo -e "\n"
            break
            ;;
        *)
            colorized_echo red "Invalid option! Try again."
            break
            ;;
    esac

done