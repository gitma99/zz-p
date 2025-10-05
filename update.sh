#!/bin/bash

GITHUB_REPO_ADDRESS='https://github.com/gitma99/zz-p.git'
APP_DIR="/var/www/html/ZZPanelBot"
BOT_CONFIG_JSON_PATH="${APP_DIR}/bot_config.json"

. /etc/os-release

if [[ "$ID" != "ubuntu" ]]; then
  echo "Error: This script only runs on Ubuntu."
  exit 1
fi

if [[ "$VERSION_ID" != 20.* ]]; then
  echo "Error: This script only supports Ubuntu 20.x (e.g., 20.04, 20.10)."
  exit 1
fi

echo "Running on Ubuntu $VERSION_ID. Continuing..."

if [ "$(id -u)" -ne 0 ]; then
    echo -e "\033[33mPlease run as root\033[0m"
    exit
fi

wait 
# -----------------------
# Helpers
# -----------------------
log()    { printf "\e[1;34m[INFO]\e[0m %s\n" "$*"; }
ok()     { printf "\e[1;32m[OK]\e[0m %s\n" "$*"; }
warn()   { printf "\e[1;33m[WARN]\e[0m %s\n" "$*"; }
fatal()  { printf "\e[1;31m[ERROR]\e[0m %s\n" "$*"; exit 1; }

log "\n[+] - Please wait for a few seconde !"

echo " "

question="Please select your action?"
actions=("Update Telegram Bot" "Delete Bot" "Exit")


select opt in "${actions[@]}"
do
    case $opt in 
        "Update Telegram Bot")
            echo -e "\n"

            read -r -p "Are you sure you want to update? [y/n] : " answer
            if [ "$answer" = "${answer#[Yy]}" ]; then
                fatal "Update Canceled !"
            fi

            if [ ! -d "${APP_DIR}" ]; then
                fatal "The BotPanel folder was not found for the update process, install the bot first!"
            fi

            if [ ! -f "$BOT_CONFIG_JSON_PATH" ]; then
                fatal "The BotPanel.install file was not found and the update process was canceled!"
            fi

            if [ ! -s "$BOT_CONFIG_JSON_PATH" ]; then
                fatal "The BotPanel.install file is empty!"
            fi

            log "Please wait, Updating . . ."
            mv $BOT_CONFIG_JSON_PATH /var/www/html/bot_config.json
            # mv "${APP_DIR}/install/zanbor.install" /var/www/html/zanbor.install
            sleep 1
            rm -r "${APP_DIR}"
            log "\nAll file and folder deleted for update bot . . .\n"

            git clone $GITHUB_REPO_ADDRESS "${APP_DIR}"
            mkdir "${APP_DIR}/Logs"
            mkdir "${APP_DIR}/Temp"
            sudo chmod -R 777 "${APP_DIR}"
            mv /var/www/html/bot_config.json $BOT_CONFIG_JSON_PATH
            sudo chmod 777 $BOT_CONFIG_JSON_PATH
            # mv /var/www/html/zanbor.install "${APP_DIR}/install/zanbor.install"
            sleep 2
            
            # content=$(cat "${APP_DIR}/install/zanbor.install")
            content=$(cat $BOT_CONFIG_JSON_PATH)
            token=$(echo "$content" | jq -r '.token')
            dev=$(echo "$content" | jq -r '.dev')
            domain=$(echo "$content" | jq -r '.main_domin')
            db_name=$(echo "$content" | jq -r '.database.db_name')
            db_username=$(echo "$content" | jq -r '.database.db_username')
            db_password=$(echo "$content" | jq -r '.database.db_password')

            source_file=$BOT_CONFIG_JSON_PATH
            destination_file="$BOT_CONFIG_JSON_PATH.tmp"
            replace=$(cat "$source_file" | sed -e "s/\[\*TOKEN\*\]/${token}/g" -e "s/\[\*DEV\*\]/${dev}/g" -e "s/\[\*DB-NAME\*\]/${db_name}/g" -e "s/\[\*DB-USER\*\]/${db_username}/g" -e "s/\[\*DB-PASS\*\]/${db_password}/g")
            echo "$replace" > "$destination_file"
            mv "$destination_file" "$source_file"

            sleep 1
            sudo chmod 777 $BOT_CONFIG_JSON_PATH
            sleep 1
            sleep 1

            curl --location "https://${domain}/ZZPanelBot/sql/sql.php?db_password=${db_password}&db_name=${db_name}&db_username=${db_username}"
            echo -e "\n"
            # ============================= reset apache server and telegram webhook ============================= #
            echo "" > "${APP_DIR}/error.log"
            
            log "\n\nMigrating:"
            curl --insecure https://127.0.0.1/ZZPanelBot/migrate.php

            sudo systemctl restart apache2
            curl -s -X POST "https://api.telegram.org/bot${token}/setWebhook"  -d url="https://${domain}/ZZPanelBot/index.php" -d drop_pending_updates="true"
            
            TEXT_MESSAGE="✅ ربات شما با موفقیت به آخرین نسخه آپدیت شد."$'\n\n'"#️⃣ اطلاعات ربات :"$'\n\n'"▫️token: <code>${token}</code>"$'\n'"▫️admin: <code>${dev}</code> "$'\n'"▫️domain: <code>${domain}</code>"$'\n'"▫️db_name: <code>${db_name}</code>"$'\n'"▫️db_username: <code>${db_username}</code>"$'\n'"▫️db_password: <code>${db_password}</code>"
            curl -s -X POST "https://api.telegram.org/bot${token}/sendMessage" -d chat_id="${dev}" -d text="${TEXT_MESSAGE}" -d parse_mode="html"

            sleep 2
            clear
            echo -e "\n\n"
            ok "The BotPanel Bot Has Been Successfully Updated"
            log "Your Bot Information:\n"
            log "[+] token: ${token}"
            log "[+] admin: ${dev}"
            log "[+] domain: ${domain}"
            log "[+] db_name: ${db_name}"
            log "[+] db_username: ${db_username}"
            log "[+] db_password: ${db_password}"
            echo -e "\n"

            break;;
        "Delete Bot")
            echo -e "\n"

            read -p "Are you sure you want to delete bot? [y/n] : " answer
            if [ "$answer" = "${answer#[Yy]}" ]; then
                fatal "Delete Canceled !"
            fi

            if [ ! -d "${APP_DIR}" ]; then
                fatal "The BotPanel folder was not found for the update process, install the bot first!"
            fi

            content=$(cat $BOT_CONFIG_JSON_PATH)
            TOKEN=$(echo "$content" | jq -r '.token')
            CHAT_ID=$(echo "$content" | jq -r '.dev')

            log "Please wait, Deleting ...\n"
            rm -r "${APP_DIR}"

            sleep 2

            TEXT_MESSAGE="❌ The BotPanel Bot Has Been Successfully Deleted"
            curl -s -X POST "https://api.telegram.org/bot${TOKEN}/sendMessage" -d chat_id="${CHAT_ID}" -d text="${TEXT_MESSAGE}"

            sleep 2
            echo -e "\n"
            ok "The BotPanel Bot Has Been Successfully Deleted"
            echo -e "\n"

            break;;
        "Exit")
            echo -e "\n"
            ok "Exited !"
            echo -e "\n"

            break;;
            *) echo "Invalid option!"
    esac
done
