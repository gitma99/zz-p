#!/bin/bash
# A script to initialize Let's Encrypt certificates securely.
# Strategy:
#   1. Start Nginx with a minimal HTTP-only config (no SSL, no upstreams) to serve ACME challenge.
#   2. Generate dummy fallback cert (for default_server 443 block in full config).
#   3. Obtain the real Let's Encrypt certificate.
#   4. Stop the bootstrap Nginx and let docker compose start the full stack normally.

set -e

# Navigate to the project root (two levels up from docker/nginx/)
cd "$(dirname "$0")/../.."

# -------------------------------------------------------
# Checks
# -------------------------------------------------------
if ! [ -x "$(command -v docker)" ]; then
  echo 'Error: docker is not installed.' >&2
  exit 1
fi

if ! docker compose version &>/dev/null; then
  echo 'Error: docker compose plugin is not installed.' >&2
  exit 1
fi

if [ ! -f .env ]; then
  echo "Error: .env file not found. Copy .env.example to .env and fill in configuration."
  exit 1
fi

source .env

if [ -z "$DOMAIN" ] || [ -z "$CERTBOT_EMAIL" ]; then
  echo "Error: DOMAIN and CERTBOT_EMAIL must be set in .env"
  exit 1
fi

# -------------------------------------------------------
# Paths
# -------------------------------------------------------
data_path="./data/certbot"
rsa_key_size=4096
staging=0 # Set to 1 if you're testing to avoid Let's Encrypt rate limits

mkdir -p "$data_path/conf" "$data_path/www"

# -------------------------------------------------------
# Step 1: Generate dummy fallback cert (for default_server SSL block in the full config)
# -------------------------------------------------------
if [ ! -f "$data_path/conf/dummy/fullchain.pem" ]; then
  echo "### Generating dummy fallback SSL certificate (for default_server block) ..."
  mkdir -p "$data_path/conf/dummy"
  docker compose run --rm --entrypoint "openssl" certbot \
    req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -keyout /etc/letsencrypt/dummy/privkey.pem \
    -out /etc/letsencrypt/dummy/fullchain.pem \
    -subj "/CN=example.com"
  echo "### Done."
fi

# -------------------------------------------------------
# Step 2: Start Nginx with minimal bootstrap config (HTTP only, no upstreams)
# -------------------------------------------------------
echo "### Remove old Nginx init container if it exists ..."
docker rm -f zzpanel_nginx_init 2>/dev/null || true
echo "### Starting Nginx with minimal bootstrap config ..."
docker run --rm -d \
  --name zzpanel_nginx_init \
  --network "$(basename "$(pwd)")_default" 2>/dev/null || \
docker run --rm -d \
  --name zzpanel_nginx_init \
  -p 80:80 \
  -v "$(pwd)/docker/nginx/nginx-init.conf:/etc/nginx/conf.d/default.conf:ro" \
  -v "$(pwd)/data/certbot/www:/var/www/certbot:ro" \
  -v "$(pwd)/data/certbot/www:/var/www/certbot:ro" \
  -v "$(pwd)/logs/certbot/nginx:/var/log/nginx" \
  -v "$(pwd)/logs/certbot/letsencrypt:/var/log/letsencrypt" \
  nginx:alpine

# Give Nginx a moment to start
sleep 2
echo "### Nginx init container is running."

# -------------------------------------------------------
# Step 3: Obtain real Let's Encrypt certificate
# -------------------------------------------------------
echo "### Requesting Let's Encrypt certificate for $DOMAIN ..."

email_arg="--email $CERTBOT_EMAIL"
staging_arg=""
if [ $staging -ne 0 ]; then staging_arg="--staging"; fi

docker compose run --rm --entrypoint "certbot" certbot \
  certonly --webroot -w /var/www/certbot \
  $staging_arg \
  $email_arg \
  -d "$DOMAIN" \
  --rsa-key-size $rsa_key_size \
  --agree-tos \
  --force-renewal \
  --non-interactive


echo "### Certificate obtained successfully."

# -------------------------------------------------------
# Step 4: Stop bootstrap Nginx
# -------------------------------------------------------
echo "### Stopping bootstrap Nginx ..."
docker stop zzpanel_nginx_init 2>/dev/null || true
echo

echo "### SSL initialization complete!"
echo "### You can now run:  docker compose up -d"
