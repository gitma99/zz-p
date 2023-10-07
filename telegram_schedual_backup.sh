#!/bin/bash

target_time_zone='Asia/Tehran'
# CONFIG_JSON='/var/www/html/ZanborPanelBot/backup_service_config.json'
CONFIG_JSON='/root/backup_service_config.json'

telegram_config_content=$(cat $CONFIG_JSON)
BOT_TOKEN=$(echo "$telegram_config_content" | jq -r '.token')
USER_ID=$(echo "$telegram_config_content" | jq -r '.chat_id')

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

send_backup(){
        if [ -d "/var/www/html/ZanborPanelBot" ]; then
        if [ -f "/var/www/html/ZanborPanelBot/bot_config.json" ]; then
            if [ -s "/var/www/html/ZanborPanelBot/bot_config.json" ]; then

                colorized_echo green "Please wait, Backuping . . ."
                content=$(cat "/var/www/html/ZanborPanelBot/bot_config.json")
                db_name=$(echo "$content" | jq -r '.database.db_name')
                db_username=$(echo "$content" | jq -r '.database.db_username')
                db_password=$(echo "$content" | jq -r '.database.db_password')
                mysqldump -u $db_username -p$db_password $db_name > /root/zz-p-sql.sql
                if [ -f "/root/zz-p-sql.sql" ]; then
                    zip /root/zz-p-backup.zip /var/www/html/ZanborPanelBot/bot_config.json /root/zz-p-sql.sql
                    if [ -f "/root/zz-p-backup.zip" ]; then
                        colorized_echo green "Backup Finshed. located in (/root/zz-p-baclup.zip)."
                        
                        curl -X POST \
                            -F "document=@/root/zz-p-backup.zip" \
                            -F "chat_id=$USER_ID" \
                            https://api.telegram.org/bot$BOT_TOKEN/sendDocument

                    else
                        colorized_echo red "backup Failed. (Cant create zip file)"
                    fi
                else
                    colorized_echo red "backup Failed. (Cant backup mysql data..)"

                fi
            else
                echo -e "\n"
                colorized_echo red "The bot_config.json file is empty!"
                echo -e "\n"
                exit 1
            fi
        else
            echo -e "\n"
            colorized_echo red "The bot_config.json file was not found and the backup process was canceled!"
            echo -e "\n"
            exit 1
        fi
    else
        echo -e "\n"
        colorized_echo red "The ZanborPanelBot folder was not found for the backup process, install the bot first!"
        echo -e "\n"
        exit 1
    fi
}



while true; do
    send_backup 

    current_time=$(TZ="$target_time_zone" date +%H:%M)
    current_seconds=$(TZ="$target_time_zone" date -d "$current_time" +%s)

    tomorrow_midnight_seconds=$(TZ="$target_time_zone" date -d '00:00 tomorrow' +%s)
    
    time_remaining=$(($tomorrow_midnight_seconds - $current_seconds))

    echo "\n"
    echo "Chron Info : $time_remaining secconds until next backup!"
    sleep $time_remaining
done