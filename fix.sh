#!/bin/bash

GITHUB_REPO_ADDRESS='https://github.com/gitma99/zz-p.git'
CONFIG_JSON='/var/www/html/ZanborPanelBot/bot_config.json'

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
                if [ -d "/var/www/html/ZanborPanelBot" ]; then
                    # if [ -f "/var/www/html/ZanborPanelBot/install/zanbor.install" ]; then
                    if [ -f "/var/www/html/ZanborPanelBot/bot_config.json" ]; then
                        # if [ -s "/var/www/html/ZanborPanelBot/install/zanbor.install" ]; then
                        if [ -s "/var/www/html/ZanborPanelBot/bot_config.json" ]; then
                            content=$(cat $CONFIG_JSON)
                            token=$(echo "$content" | jq -r '.token')
                            dev=$(echo "$content" | jq -r '.dev')
                            domain=$(echo "$content" | jq -r '.main_domin')
                            sleep 1

                            # ============================= reset apache server and telegram webhook ============================= #
                            echo "" > /var/www/html/ZanborPanelBot/error.log
                            sudo systemctl restart apache2
                            curl -s -X POST "https://api.telegram.org/bot${token}/setWebhook"  -d url="https://${domain}/ZanborPanelBot/index.php" -d drop_pending_updates="true"
                            
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
