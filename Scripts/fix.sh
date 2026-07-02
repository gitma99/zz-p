#!/bin/bash

GITHUB_REPO_ADDRESS='https://github.com/gitma99/zz-p.git'
APP_DIR="/var/www/html/ZZPanelBot"
BOT_CONFIG_JSON_PATH="${APP_DIR}/bot_config.json"

if [ "$(id -u)" -ne 0 ]; then
    echo -e "\033[33mPlease run as root\033[0m"
    exit
fi

wait 

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
echo $BOT_CONFIG_JSON_PATH
colorized_echo green "\n[+] - Please wait for a few seconde !"

echo " "

question="Please select your action?"
actions=("Fix telegram Webhook" "Exit")


select opt in "${actions[@]}"
do
    case $opt in 
        "Fix telegram Webhook")
            echo -e "\n"
            read -p "Are you sure you want to update? [y/n] : " answer
            if [ "$answer" != "${answer#[Yy]}" ]; then
                if [ -d "${APP_DIR}" ]; then
                    if [ -f "$BOT_CONFIG_JSON_PATH" ]; then
                        if [ -s "$BOT_CONFIG_JSON_PATH" ]; then
                            apt install -y jq
                            content=$(cat $BOT_CONFIG_JSON_PATH)
                            token=$(echo "$content" | jq -r '.token')
                            dev=$(echo "$content" | jq -r '.dev')
                            domain=$(echo "$content" | jq -r '.main_domin')
                            sleep 1

                            # ============================= reset apache server and telegram webhook ============================= #
                            echo "" > "${APP_DIR}/logs/error.log"
                            sudo systemctl restart apache2
                            curl -s -X POST "https://api.telegram.org/bot${token}/setWebhook"  -d url="https://${domain}/ZZPanelBot/index.php" -d drop_pending_updates="true"
                            
                            sleep 2
                            clear
                            echo -e "\n\n"
                            colorized_echo green "[+] Telegram Webhook Successfully Updated"
                            colorized_echo green "Your Bot Information:\n"
                            colorized_echo blue "[+] token: ${token}"
                            colorized_echo blue "[+] admin: ${dev}"
                            colorized_echo blue "[+] domain: ${domain}"
                            echo -e "\n"
                        else
                            echo -e "\n"
                            colorized_echo red "The BotPanel.install file is empty!"
                            echo -e "\n"
                            exit 1
                        fi
                    else
                        echo -e "\n"
                        colorized_echo red "The BotPanel.install file was not found and the update process was canceled!"
                        echo -e "\n"
                        exit 1
                    fi
                else
                    echo -e "\n"
                    colorized_echo red "The BotPanel folder was not found for the update process, install the bot first!"
                    echo -e "\n"
                    exit 1
                fi
            else
                echo -e "\n"
                colorized_echo red "Update Canceled !"
                echo -e "\n"
                exit 1
            fi

            break;;
        "Exit")
            echo -e "\n"
            colorized_echo green "Exited !"
            echo -e "\n"

            break;;
            *) echo "Invalid option!"
    esac
done
