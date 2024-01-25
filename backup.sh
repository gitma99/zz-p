#!/bin/bash


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

#choices : backup - restore
question="Please select your action?"
actions=("Backup" "Restore" "Exit")





select opt in "${actions[@]}"
do
    case $opt in 
        "Backup")
            echo -e "\n"
            read -p "Are you sure you want to Backup? [y/n] : " answer
            if [ "$answer" != "${answer#[Yy]}" ]; then
                if [ -d "/var/www/html/ZanborPanelBot" ]; then
                    if [ -f "/var/www/html/ZanborPanelBot/bot_config.json" ]; then
                        if [ -s "/var/www/html/ZanborPanelBot/bot_config.json" ]; then

                            sudo apt update && apt upgrade -y
                            sudo apt install jq -y
                            sudo apt install zip -y
                            
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
                                    exit 1
                                else
                                    colorized_echo red "backup Failed. (Cant create zip file)"
                                    exit 1
                                fi
                            else
                                colorized_echo red "backup Failed. (Cant backup mysql data..)"
                                exit 1
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
                    colorized_echo red "The BotPanel folder was not found for the backup process, install the bot first!"
                    echo -e "\n"
                    exit 1
                fi
            else
                echo -e "\n"
                colorized_echo red "Backup Canceled !"
                echo -e "\n"
                exit 1
            fi

            break;;


        "Restore")
            echo -e "\n"
            read -p "Are you sure you want to Restore? [y/n] : " answer
            if [ "$answer" != "${answer#[Yy]}" ]; then
                if [ -d "/var/www/html/ZanborPanelBot" ]; then
                    if [ -f "/var/www/html/ZanborPanelBot/bot_config.json" ]; then
                        if [ -s "/var/www/html/ZanborPanelBot/bot_config.json" ]; then

                            sudo apt update && apt upgrade -y
                            sudo apt install jq -y
                            sudo apt install zip -y
                            
                            colorized_echo green "Please wait, Restoring data . . ."
                            # ================== extract Backup Data ====================
                            if [ -f "/root/zz-p-backup.zip" ]; then
                                unzip /root/zz-p-backup.zip -d /root/extracted_data
                            else
                                colorized_echo red "Restore Failed. (Couldn't find zz-p-backup.zip)"
                                exit 1
                            fi
                            # ================== Restore bot_config.json ======================
                            if [ -f "/root/zz-p-backup.zip" ]; then
                                cp "/root/extracted_data/var/www/html/ZanborPanelBot/bot_config.json" "/var/www/html/ZanborPanelBot/bot_config.json"

                                content=$(cat "/var/www/html/ZanborPanelBot/bot_config.json")
                                db_name=$(echo "$content" | jq -r '.database.db_name')
                                db_username=$(echo "$content" | jq -r '.database.db_username')
                                db_password=$(echo "$content" | jq -r '.database.db_password')
                            else
                                colorized_echo red "Restore Failed. (Couldn't extract zip file.)"
                                exit 1
                            fi

                            if [ "$(echo "$(cat "/var/www/html/ZanborPanelBot/bot_config.json")" | jq -r '.database.db_name')" = "$db_name" ]; then
                                # ================== Restore Database ======================
                                mysql -e "CREATE USER '$db_username'@'localhost' IDENTIFIED BY '$db_password';GRANT ALL PRIVILEGES ON *.* TO '$db_username'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;"
                                mysql -u $db_username -p$db_password -e "CREATE DATABASE $db_name" 
                                mysql -u $db_username -p$db_password $db_name < /root/extracted_data/root/zz-p-sql.sql
                                if [ true ]; then
                                    colorized_echo green "Restore Finshed."
                                else
                                    colorized_echo red "Restore Failed. (Couldn't Restore database!!)"
                                fi
                            else
                                colorized_echo red "Restore Failed. (Couldn't update bot_config.json)"
                                exit 1 
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
            else
                echo -e "\n"
                colorized_echo red "Backup Canceled !"
                echo -e "\n"
                exit 1
            fi

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
# get files 
# zip them


# get zip
# extract
# get content from jason
# create a user in mysql with the info provided
# clone database to server 
# test datatbase
