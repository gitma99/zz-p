#!/bin/bash
# === define global variables.
SCRIPT_PATH="/var/www/html/ZanborPanelBot/Scripts/telegram_schedual_backup.sh"
LOG_PATH="/var/www/html/ZanborPanelBot/Logs/backup.log"

SERVICE_FILE_TEMPLATE_PATH="/var/www/html/ZanborPanelBot/Templates/zz-backup.service"
SERVICE_FILE_NAME="zz-backup.service"

telegram_config_json_TEMPLATE_PATH="/var/www/html/ZanborPanelBot/Templates/backup_service_config.json"
telegram_config_json_PATH="/root/backup_service_config.json"

# ==== define base functions
colorized_echo() {
    local color=$1
    local text=$2
    
    case $color in
        "red")
        printf "\e[91m${text}\e[0m\n";;
        "green")
        printf "\e[92m${text}\e[0m\n";;
        "yellow")
        printf "\e[93m${text}\e[0m\n";;
        "blue")
        printf "\e[94m${text}\e[0m\n";;
        "magenta")
        printf "\e[95m${text}\e[0m\n";;
        "cyan")
        printf "\e[96m${text}\e[0m\n";;
        *)
            echo "${text}"
        ;;
    esac
}

handle_error() {
    local ERROR_MSG=$1
    local LINE_NO=$2
    colorized_echo red "[$(date +"%Y-%m-%d %H:%M:%S")] ERROR: Error on line $LINE_NO: $ERROR_MSG"
    exit 1
    # You can add more error handling logic here if needed
}

# ==== Trap errors and call the handle_error function
trap 'handle_error "$BASH_COMMAND" $LINENO' ERR

# ==== ensure root access.
if [[ $EUID -ne 0 ]]; then
    handle_error "run script using as root or 'sudo'!"
    exit 1
fi

# ==== check files exists.
if [ ! -f "$SCRIPT_PATH" ]; then
    handle_error "File $SCRIPT_PATH does not exist" $LINENO
    exit 1
fi

if [ ! -f "$SERVICE_FILE_TEMPLATE_PATH" ]; then
    handle_error "File $SERVICE_FILE_TEMPLATE_PATH does not exist" $LINENO
    exit 1
fi

if [ ! -f "$telegram_config_json_TEMPLATE_PATH" ]; then
    handle_error "File $telegram_config_json_TEMPLATE_PATH does not exist" $LINENO
    exit 1
fi

# ==== script options for user
colorized_echo green "\n[+] - Please wait for a few seconde !"
echo " "
question="Please select your action?"
actions=("Create Backup Task" "Delete Backup Task" "Disable Service" "Enable Service" "Show Logs" "Exit")
select opt in "${actions[@]}"
do
    case $opt in 
        "Create Backup Task")
            colorized_echo cyan "Enter 'Telegram Token' :"
            printf "" 
            read TOKEN
            colorized_echo cyan "Enter target Telegram 'chat id' number :" 
            printf "" 
            read CHAT_ID

            # ==== install required dependencies
            apt install zip curl -qqq -y

            # ==== telegram works
            telegram_config_json_TEMP_PATH="$telegram_config_json_TEMPLATE_PATH.tmp"
            replace=$(cat "$telegram_config_json_TEMPLATE_PATH" | sed -e "s/\[\*TOKEN\*\]/${TOKEN}/g" -e "s/\[\*CHAT\*\]/${CHAT_ID}/g")
            echo "$replace" > "$telegram_config_json_TEMP_PATH"
            mv -f "$telegram_config_json_TEMP_PATH" "$telegram_config_json_PATH"

            # ==== Create the systemd service unit file
            SERVICE_FILE_TEMP_PATH="/tmp/$SERVICE_FILE_NAME"

            # cp template file to temp dir.
            cp -f $SERVICE_FILE_TEMPLATE_PATH $SERVICE_FILE_TEMP_PATH

            # Open the file and replace some text
            if [ -f "$SERVICE_FILE_TEMP_PATH" ]; then
                sed -i 's/{{BACKUP-SCRIPT-PATH}}/'"$SCRIPT_PATH"'/g' $SERVICE_FILE_TEMP_PATH || handle_error "Failed to replace text in $SERVICE_FILE_TEMP_PATH" $LINENO
                sed -i 's/{{LOG-PATH}}/'"$LOG_PATH"'/g' $SERVICE_FILE_TEMP_PATH || handle_error "Failed to replace text in $SERVICE_FILE_TEMP_PATH" $LINENO
            else
                handle_error "File $SERVICE_FILE_TEMP_PATH does not exist" $LINENO
            fi

            cp -f $SERVICE_FILE_TEMP_PATH /etc/systemd/system/$SERVICE_FILE_NAME

            # remove temp files
            rm -f $SERVICE_FILE_TEMP_PATH

            # give permissions
            chmod 755 /etc/systemd/system/$SERVICE_FILE_NAME

            colorized_echo green "INFO: Successfully created $SERVICE_FILE_NAME."

            # ==== start service
            systemctl daemon-reload
            systemctl enable "$SERVICE_FILE_NAME"
            systemctl start "$SERVICE_FILE_NAME"

            colorized_echo green "INFO: Service $SERVICE_FILE_NAME has been started."

            systemctl status "$SERVICE_FILE_NAME"
            break;;
        "Delete Backup Task")
            systemctl stop "$SERVICE_FILE_NAME"
            systemctl disable "$SERVICE_FILE_NAME"
            rm "/etc/systemd/system/$SERVICE_FILE_NAME"
            systemctl daemon-reload

            echo "Service $SERVICE_FILE_NAME has been disabled and removed."
            break;;
        "Disable Service")
            systemctl stop "$SERVICE_FILE_NAME"
            systemctl disable "$SERVICE_FILE_NAME"

            echo "Service $SERVICE_FILE_NAME has been disabled."

            break;;
        "Enable Service")
            systemctl start "$SERVICE_FILE_NAME"
            systemctl enable "$SERVICE_FILE_NAME"

            echo "Service $SERVICE_FILE_NAME has been enabled."
            break;;
        "Show Logs")
            tail -f $LOG_PATH
            exit 0
            break;;
        "Exit")
            echo -e "\n"
            colorized_echo green "Exited !"
            echo -e "\n"
            break;;
        *) 
            echo "Invalid option!"
            break;;
    esac
done