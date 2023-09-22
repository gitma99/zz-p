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

DEFAULT_PHPMYADMIN_USERNAME="phpadmin"
colorized_echo green "Enter the a username for phpmyadmin (for backup and editing database stuf) (For Default -> Enter): "
printf "[+] Default username is [$DEFAULT_PHPMYADMIN_USERNAME] :"
read  PHPMYADMIN_USERNAME
if [ "$PHPMYADMIN_USERNAME" = "" ]; then
    PHPMYADMIN_USERNAME=$DEFAULT_PHPMYADMIN_USERNAME
else
    PHPMYADMIN_USERNAME=$PHPMYADMIN_USERNAME
fi

DEFAULT_PHPMYADMIN_PASSWORD="php12345"
colorized_echo green "Enter the a password for phpmyadmin  (For Default -> Enter): " 
printf "[+] Default password is [$DEFAULT_PHPMYADMIN_PASSWORD] :"
read PHPMYADMIN_PASSWORD
if [ "$PHPMYADMIN_PASSWORD" = "" ]; then
    PHPMYADMIN_PASSWORD=$DEFAULT_PHPMYADMIN_PASSWORD
else
    PHPMYADMIN_PASSWORD=$PHPMYADMIN_PASSWORD
fi

mysql -e "CREATE USER '$PHPMYADMIN_USERNAME'@'localhost' IDENTIFIED BY '$PHPMYADMIN_PASSWORD';GRANT ALL PRIVILEGES ON *.* TO '$PHPMYADMIN_USERNAME'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;"