#!/bin/bash



SERVICE_NAME="zz-backup"
SCRIPT_PATH="/var/www/html/ZanborPanelBot/telegram_schedual_backup.sh"
LOG_PATH="/var/www/html/ZanborPanelBot/backup.log"
default_telegram_config_json="/var/www/html/ZanborPanelBot/DEFAULTS/backup_service_config.json"
telegram_config_json="/var/www/html/ZanborPanelBot/backup_service_config.json"

if [[ $EUID -ne 0 ]]; then
echo "This script must be run as root."
exit 1
fi



# Add a cron job to run a script every day at midnight

create_service(){
cat <<EOF > "/etc/systemd/system/$SERVICE_NAME.service"
[Unit]
Description=My Bash Script Service
After=network.target

[Service]
ExecStart=$SCRIPT_PATH
Restart=always
StandardOutput=append:$LOG_PATH
StandardError=append:$LOG_PATH

[Install]
WantedBy=multi-user.target
EOF
}

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

colorized_echo green "\n[+] - Please wait for a few seconde !"

echo " "

question="Please select your action?"
actions=("Create Backup Task" "Delete Backup Task" "Disable Backup Service" "Enable Backup Service" "Exit")


select opt in "${actions[@]}"
do
    case $opt in 
        "Create Backup Task")
            colorized_echo cyan "Enter the Token :"
            printf "" 
            read TOKEN
            colorized_echo cyan "Enter the chat id number :" 
            printf "" 
            read CHAT_ID

            apt install zip -y
            apt install curl -y

            default_file=$default_telegram_config_json
            destination_file="$default_telegram_config_json.tmp"
            replace=$(cat "$default_file" | sed -e "s/\[\*TOKEN\*\]/${TOKEN}/g" -e "s/\[\*CHAT\*\]/${CHAT_ID}/g")
            echo "$replace" > "$destination_file"
            mv "$destination_file" "$telegram_config_json"

            # Create the systemd service unit file
            create_service

            # Reload systemd
            systemctl daemon-reload

            # Enable and start the service
            systemctl enable "$SERVICE_NAME.service"
            systemctl start "$SERVICE_NAME.service"

            # Check the status
            systemctl status "$SERVICE_NAME.service"

            echo "Service $SERVICE_NAME has been created and started."


        break;;
        "Delete Backup Task")

            # Stop and disable the service
            systemctl stop "$SERVICE_NAME.service"
            systemctl disable "$SERVICE_NAME.service"

            # Remove the service unit file
            rm "/etc/systemd/system/$SERVICE_NAME.service"

            # Reload systemd
            systemctl daemon-reload

            echo "Service $SERVICE_NAME has been disabled and removed."

        break;;
        "Disable Backup Service")
            systemctl stop "$SERVICE_NAME.service"
            systemctl disable "$SERVICE_NAME.service"

            echo "Service $SERVICE_NAME has been disabled."

        break;;
        "Enable Backup Service")

            # Stop and disable the service
            systemctl start "$SERVICE_NAME.service"
            systemctl enable "$SERVICE_NAME.service"


            echo "Service $SERVICE_NAME has been enabled."

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