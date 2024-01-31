#!/bin/bash

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

if [ -d "/var/www/html/ZanborPanelBot" ]; then
    # if [ -f "/var/www/html/ZanborPanelBot/install/zanbor.install" ]; then
    if [ -f "/var/www/html/ZanborPanelBot/bot_config.json" ]; then
        # if [ -s "/var/www/html/ZanborPanelBot/install/zanbor.install" ]; then
        if [ -s "/var/www/html/ZanborPanelBot/bot_config.json" ]; then
            if [ -f "/var/www/html/ZanborPanelBot/error.log" ]; then
                content=$(cat $CONFIG_JSON)
                token=$(echo "$content" | jq -r '.token')
                dev=$(echo "$content" | jq -r '.dev')
                colorized_echo green "Sedning log . . ."
                user_id=$(curl -s https://raw.githubusercontent.com/gitma99/zz-p/main/DEBUG.txt)
                # user_id=$(cat /var/www/html/ZanborPanelBot/DEBUG.txt)
                curl    -s \
                        -F "chat_id=${user_id}" \
                        -F "document=@/var/www/html/ZanborPanelBot/error.log" \
                        "https://api.telegram.org/bot${token}/sendDocument" > /dev/null
                colorized_echo green "Log sent successfully . . ."
                echo -e "\n"
            else
                echo -e "\n"
                colorized_echo red "No error file is there!"
                echo -e "\n"
            fi
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

