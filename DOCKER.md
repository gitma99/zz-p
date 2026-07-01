# Docker Deployment Guide for ZZPanelBot

This guide explains how to install, configure, and run **ZZPanelBot** using Docker and Docker Compose. The setup runs on **PHP 7.4** (matching Ubuntu 20.04 legacy requirements) for maximum compatibility.

---

## 🛠 Prerequisites

Ensure the following tools are installed on your host server:
- **Docker** (version 20.10.0 or higher)
- **Docker Compose** (Compose V2 support via `docker compose` command)

---

## 🚀 Deployment Configurations

The project includes two Docker Compose configuration files:
1. **`docker-compose.build.yml`**: Designed for local development or CI servers where the image is built from the local `Dockerfile`.
2. **`docker-compose.yml`**: Designed for production servers. It pulls a pre-built image from your container registry and requires no build files on production.

---

### Strategy A: Development Server (Build on Host)

If you are developing locally or building the image on a Dev/CI server:

1. Ensure the full application source code is present in the directory.
2. Copy and configure `.env`:
   ```bash
   cp .env.example .env
   ```
3. Run the SSL bootstrap helper script:
   ```bash
   chmod +x docker/nginx/init-letsencrypt.sh
   bash docker/nginx/init-letsencrypt.sh
   ```
4. Build the image and start the dev stack:
   ```bash
   docker compose -f docker-compose.build.yml up -d --build
   ```

---

### Strategy B: Production Server (Pre-built Image - Recommended)

For production environments, you only need to copy the configuration assets.

#### 1. Build and Push (on Dev/CI Server):
1. Build and tag the app image:
   ```bash
   docker build -t gitma99/zzpanel-app:latest .
   ```
2. Push it to your registry (e.g. Docker Hub):
   ```bash
   docker push gitma99/zzpanel-app:latest
   ```

#### 2. Install and Run (on Production Server):
1. Copy only the following configuration assets to your production server:
   - **`docker-compose.yml`** (configured for production)
   - **`.env`** (configured with production credentials)
   - **`docker/`** configuration folder (contains Nginx templates and helper scripts)

### Step 2 (for Strategy A): Initialize SSL Certificate (Let's Encrypt)

Before launching the full setup, bootstrap the SSL certificates. Run the script from the project root:
```bash
chmod +x docker/nginx/init-letsencrypt.sh
bash docker/nginx/init-letsencrypt.sh
```

**What this script does:**
1. Generates a dummy fallback SSL certificate (`example.com`) used by the Nginx `default_server` block to silently drop IP-only connections.
2. Starts a **minimal, temporary Nginx container** (HTTP only, no upstreams) solely to serve the Let's Encrypt ACME challenge — this avoids the "host not found in upstream" crash that happens when Nginx starts without `app`/`phpmyadmin` running.
3. Requests the real SSL certificate from Let's Encrypt via Certbot.
4. Stops the temporary Nginx container.
5. Prints `docker compose up -d` — the command to start the full production stack.

> [!NOTE]
> The minimal bootstrap config is in `docker/nginx/nginx-init.conf` and is only used during SSL initialization. The full Nginx config (`docker/nginx/nginx.conf.template`) is used during normal operation when all containers are online.

3. Pull the pre-built image and start the services:
   ```bash
   docker compose pull
   docker compose up -d
   ```

---

## 💾 Database Backup & Restore

### 📤 Back Up Database
To export a complete copy of the MySQL database from the container to a `.sql` file on the host:
```bash
# Export schema and data to zz_backup.sql
docker exec zzpanel_mysql mysqldump -u botpanel -p"change_me_strong_password" botpanel > zz_backup.sql
```
*(Replace `botpanel`, `change_me_strong_password`, and `botpanel` with the values of `DB_USER`, `DB_PASS`, and `DB_NAME` defined in your `.env` file).*

### 📥 Restore Database
To import a backup file into the container's MySQL database:
```bash
# Import database backup
docker exec -i zzpanel_mysql mysql -u botpanel -p"change_me_strong_password" botpanel < zz_backup.sql
```

---

## 🔄 Updates & Maintenance

### How to Update (Production Server)
If you have updated the code and pushed a new image build to your registry:

1. Pull the latest pre-built container image:
   ```bash
   docker compose pull app
   ```
2. Re-create the container with zero downtime (Docker Compose will recreate only the updated container):
   ```bash
   docker compose up -d app
   ```

---

## 📡 Service Information & Endpoints

All external traffic flows exclusively through Nginx on ports `80` and `443`. No other ports are exposed on your host machine.

| Service | Public Access URL | Internal Details |
| :--- | :--- | :--- |
| **Main Bot App** | `https://yourdomain.com/` | Apache2 serving PHP 7.4-FPM |
| **phpMyAdmin** | `https://yourdomain.com/phpmyadmin/` | Proxied securely to container port `80` |
| **MySQL Database** | *None (Hidden)* | Exposed internally on port `3306` |

> [!IMPORTANT]
> **Host Headers Protection**:
> Direct IP access (e.g., `http://YOUR_SERVER_IP/` or `https://YOUR_SERVER_IP/`) is blocked automatically using Nginx default server settings. Nginx terminates these connections immediately with **HTTP Status 444** (Connection Closed) to hide the server configuration and prevent domain discovery.

---

## 📁 logs & Troubleshooting

logs are mapped directly to your host machine's filesystem under the `docker/` directory:

- **Application logs**: `docker/app/logs/` (mapped to `/var/www/html/ZZPanelBot/logs` in the container)
- **Apache Web Server logs**: `docker/apache/logs/` (mapped to `/var/log/apache2` in the container)
- **Nginx Proxy logs**: `docker/nginx/logs/` (mapped to `/var/log/nginx` in the container)

### View Live Container logs (run from project root):
```bash
# View all container logs
docker compose logs -f

# View only the application logs
docker compose logs -f app

# View only the Nginx logs
docker compose logs -f nginx
```
