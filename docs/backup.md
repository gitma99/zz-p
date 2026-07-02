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
2. Calculates the remaining time until midnight (Tehran time / `Asia/Tehran`).
3. Sleeps until midnight, performs another backup, sends it to Telegram, and repeats.

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
