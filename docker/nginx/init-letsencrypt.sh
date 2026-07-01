#!/bin/bash
# A script to initialize Let's Encrypt certificates securely

# Navigate to the project root directory (grandparent of the script's parent location)
cd "$(dirname "$0")/../.."

if ! [ -x "$(command -v docker)" ]; then
  echo 'Error: docker is not installed.' >&2
  exit 1
fi

if ! [ -x "$(command -v docker-compose)" ] && ! docker compose version &>/dev/null; then
  echo 'Error: docker compose is not installed.' >&2
  exit 1
fi

# Load .env file from project root
if [ ! -f .env ]; then
  echo "Error: .env file not found at project root. Copy .env.example to .env and fill in configuration."
  exit 1
fi

source .env

if [ -z "$DOMAIN" ] || [ -z "$CERTBOT_EMAIL" ]; then
  echo "Error: DOMAIN and CERTBOT_EMAIL must be set in .env"
  exit 1
fi

data_path="./docker/certbot"
rsa_key_size=4096
staging=0 # Set to 1 if you're testing to avoid hitting limits

if [ -d "$data_path/conf/live/$DOMAIN" ]; then
  read -p "Existing data found for $DOMAIN. Continue and replace existing certificate? (y/N) " decision
  if [ "$decision" != "Y" ] && [ "$decision" != "y" ]; then
    exit 0
  fi
fi

if [ ! -e "$data_path/conf/options-ssl-nginx.conf" ] || [ ! -e "$data_path/conf/ssl-dhparams.pem" ]; then
  echo "### Downloading recommended TLS parameters ..."
  mkdir -p "$data_path/conf"
  curl -s https://raw.githubusercontent.com/certbot/certbot/master/certbot-nginx/certbot_nginx/_internal/tls_configs/options-ssl-nginx.conf > "$data_path/conf/options-ssl-nginx.conf"
  curl -s https://raw.githubusercontent.com/certbot/certbot/master/certbot/certbot/ssl-dhparams.pem > "$data_path/conf/ssl-dhparams.pem"
  echo
fi

echo "### Creating fallback dummy certificate for default_server ..."
mkdir -p "$data_path/conf/dummy"
docker compose run --rm --entrypoint "
  openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -keyout '/etc/letsencrypt/dummy/privkey.pem' \
    -out '/etc/letsencrypt/dummy/fullchain.pem' \
    -subj '/CN=example.com'" certbot
echo

echo "### Creating dummy certificate for $DOMAIN ..."
path="/etc/letsencrypt/live/$DOMAIN"
mkdir -p "$data_path/conf/live/$DOMAIN"
docker compose run --rm --entrypoint "
  openssl req -x509 -nodes -newkey rsa:$rsa_key_size -days 1 \
    -keyout '$path/privkey.pem' \
    -out '$path/fullchain.pem' \
    -subj '/CN=localhost'" certbot
echo

echo "### Starting nginx ..."
docker compose up --no-deps -d nginx
echo

echo "### Deleting dummy certificate for $DOMAIN ..."
docker compose run --rm --entrypoint "
  rm -Rf /etc/letsencrypt/live/$DOMAIN && \
  rm -Rf /etc/letsencrypt/archive/$DOMAIN && \
  rm -Rf /etc/letsencrypt/renewal/$DOMAIN.conf" certbot
echo

echo "### Requesting Let's Encrypt certificate for $DOMAIN ..."
# Select challenge directory
mkdir -p "$data_path/www"

# Request certificate
email_arg="--email $CERTBOT_EMAIL"
if [ -z "$CERTBOT_EMAIL" ]; then email_arg="--register-unsafely-without-email"; fi

# Enable staging mode if needed
staging_arg=""
if [ $staging -ne 0 ]; then staging_arg="--staging"; fi

docker compose run --rm --entrypoint "
  certbot certonly --webroot -w /var/www/certbot \
    $staging_arg \
    $email_arg \
    -d $DOMAIN \
    --rsa-key-size $rsa_key_size \
    --agree-tos \
    --force-renewal \
    --non-interactive" certbot
echo

echo "### Reloading nginx ..."
docker compose exec nginx nginx -s reload
