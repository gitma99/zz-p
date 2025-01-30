#!/bin/bash
# === define variables
target_time_zone='Asia/Tehran'
CONFIG_JSON_PATH='/root/backup_service_config.json'
ZZ_BOT_CONFIG_JSON_PATH='/var/www/html/ZanborPanelBot/bot_config.json'

TELEGRAM_BOT_TOKEN=$(echo "$(cat $CONFIG_JSON_PATH)" | jq -r '.token')
TELEGRAM_USER_ID=$(echo "$(cat $CONFIG_JSON_PATH)" | jq -r '.chat_id')

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
}

while true; do
    # ==== check for existence of required directories and files
    if [ ! -d "/var/www/html/ZanborPanelBot" ]; then
        echo -e "\n"
        handle_error "The BotPanel folder was not found for the backup process, install the bot first!" $LINENO
        echo -e "\n"
    fi

    if [ ! -f "$ZZ_BOT_CONFIG_JSON_PATH" ]; then
        echo -e "\n"
        handle_error "The bot_config.json file was not found and the backup process was canceled!" $LINENO
        echo -e "\n"
    fi

    if [ ! -s "$ZZ_BOT_CONFIG_JSON_PATH" ]; then
        echo -e "\n"
        handle_error "The bot_config.json file is empty!" $LINENO
        echo -e "\n"
        exit 1
    fi

    # ==== backup mysql data
    colorized_echo green "Please wait, Backuping data database data ..."
    mysqldump \
        -u "$(jq -r '.database.db_username' < "$ZZ_BOT_CONFIG_JSON_PATH")" \
        -p"$(jq -r '.database.db_password' < "$ZZ_BOT_CONFIG_JSON_PATH")" \
        "$(jq -r '.database.db_name' < "$ZZ_BOT_CONFIG_JSON_PATH")" \
        > /root/zz-p-sql.sql
        # > /root/zz-p-sql.sql 2>mysqldump_error.log
    touch mysqldump_error.log
    if [ $? -ne 0 ]; then
        handle_error "mysqldump failed: $(cat mysqldump_error.log)" $LINENO
    fi
    
    rm -f mysqldump_error.log

    if [ ! -f "/root/zz-p-sql.sql" ]; then
        handle_error "'mysql' did not backup to /root/zz-p-sql.sql!" $LINENO
        exit 1
    fi

    # ==== zip necessary files for backup
    zip /root/zz-p-backup.zip $ZZ_BOT_CONFIG_JSON_PATH /root/zz-p-sql.sql

    if [ ! -f "/root/zz-p-backup.zip" ]; then
        handle_error "'zip' could not create /root/zz-p-backup.zip!" $LINENO
        exit 1
    fi
    colorized_echo green "Backup Fished. located in (/root/zz-p-backup.zip)."

    # send zip file to Telegram:
    curl -X POST \
        -F "document=@/root/zz-p-backup.zip" \
        -F "chat_id=$TELEGRAM_USER_ID" \
        https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/sendDocument

    # ==== schedule for next backup time
    current_time=$(TZ="$target_time_zone" date +%H:%M)
    current_seconds=$(TZ="$target_time_zone" date -d "$current_time" +%s)

    tomorrow_midnight_seconds=$(TZ="$target_time_zone" date -d '00:00 tomorrow' +%s)
    
    time_remaining=$(($tomorrow_midnight_seconds - $current_seconds))

    echo "\n"
    colorized_echo blue "Chron Info : $time_remaining seconds until next backup!"
    sleep $time_remaining
done