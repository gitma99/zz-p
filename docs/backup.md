# ZZPanel Database Backup Documentation

This document explains how the Docker-native backup mechanism works for ZZPanel, including automated scheduled backups and manual on-demand backups.

---

## 1. Overview

Backups are handled by the `zzpanel_backup` container in the Docker Compose stack. 
Each backup creates a compressed ZIP file containing:
- `root/zz-p-sql.sql`: A complete SQL dump of the MySQL database.
- `var/www/html/ZZPanelBot/bot_config.json`: The bot configuration generated dynamically from the environment.

All backups are stored on the host machine in the [docker/backups/](file:///d:/3.Projects/zz-p-main/docker/backups) directory.

---

## 2. Automated Scheduled Backups

The `zzpanel_backup` service runs a background loop that:
1. Performs an initial backup immediately when the container starts.
2. By default, calculates the remaining time until midnight (Tehran time / `Asia/Tehran`).
3. Sleeps until the next run, performs another backup, sends it to Telegram, and repeats.

### Schedule Configuration
Unset/empty `BACKUP_INTERVAL_MINUTES` keeps the default midnight (`Asia/Tehran`) schedule. Set it to a fixed interval in minutes (minimum 5):
```env
# Run every 60 minutes instead of once a day at midnight
BACKUP_INTERVAL_MINUTES=60
```
Invalid values (non-integer or below 5) fail fast at startup.

### Retry Configuration
Each run retries failed backups with 10 seconds between attempts. Total backup attempts per run:
```env
# Default 3, minimum 1
BACKUP_MAX_RETRIES=3
```
Invalid values (non-integer or below 1) fail fast at startup with a Telegram alert (systemd script) and an error log. After all attempts fail, the service waits 1 hour before the next try. Note: each backup attempt itself retries the Telegram upload up to 3 times, so worst-case upload tries per run = `BACKUP_MAX_RETRIES × 3`.

### Telegram Configuration
By default, the backup service uses the main bot credentials (`BOT_TOKEN` and `CHAT_ID`) defined in your `.env` file. 

If you want to use a separate bot or send notifications to a different chat, define these optional variables in your `.env` file:
```env
# Optional: defaults to BOT_TOKEN and CHAT_ID if left empty
BACKUP_TELEGRAM_TOKEN=your_telegram_bot_token
BACKUP_CHAT_ID=your_telegram_admin_chat_id
```

---

## 3. Manual On-Demand Backup

You can trigger a manual backup at any time using either the host-side helper script or direct Docker commands.

### Option A: Using the Host Helper Script (Recommended)
Run the following command from the root of the project:
```bash
./Scripts/docker_backup.sh
```
Then choose `1` (or type `Backup`) and confirm.

### Option B: Using Direct Docker Command
Run the backup script directly inside the running container:
```bash
docker exec -it zzpanel_backup /backup.sh
```

---

## 4. Troubleshooting & Logs

To check the logs of the scheduled backup loop, run:
```bash
docker compose logs -f backup
```

The logs are also stored on the host at `docker/backups/backup.log`.
