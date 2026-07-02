# ZZPanel Database Restore Documentation

This document explains how to restore the database from a backup ZIP file in the Dockerized installation.

> [!CAUTION]
> Restoring a backup will overwrite all existing tables and data in the active database. Make sure you select the correct backup archive.

---

## 1. Using the Host Helper Script (Recommended)

The easiest way to restore a backup is using the interactive host helper script.

1. Ensure the backup ZIP file you want to restore is placed in the [docker/backups/](file:///d:/3.Projects/zz-p-main/docker/backups) directory on your host.
2. Run the helper script from the root of the project:
   ```bash
   ./Scripts/docker_backup.sh
   ```
3. Choose option `2` (`Restore`).
4. Select the backup file from the list.
5. Confirm by entering `y`.
6. Once the restore completes, the script will ask if you want to restart the application stack to ensure all cached connections are refreshed. It is highly recommended to choose `y`.

---

## 2. Manual Restore via Docker Commands

If you have a backup file located somewhere else or want to perform the restore manually:

1. Copy your backup ZIP file into the `zzpanel_backup` container:
   ```bash
   docker cp /path/to/your/backup.zip zzpanel_backup:/tmp/restore.zip
   ```
2. Execute the restore script inside the container, passing the path to the zip:
   ```bash
   docker exec -it zzpanel_backup /restore.sh /tmp/restore.zip
   ```
3. Clean up the temporary zip file inside the container:
   ```bash
   docker exec zzpanel_backup rm -f /tmp/restore.zip
   ```
4. Restart the app container to refresh state:
   ```bash
   docker compose restart app nginx
   ```
