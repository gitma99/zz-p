# Diagnose Health Check Suite for setup.sh — Design Document

## Intent and Why

When ZZPanelBot experiences outages or fails to boot, administrators often struggle to pinpoint the root cause among several interdependent components: host resource exhaustion, Docker container health, MySQL connection/migration states, Apache/PHP-FPM runtime errors, Nginx reverse proxy/SSL expiration, or Telegram API network filtering and webhook error backlogs.

This change adds an interactive and CLI-accessible diagnostic suite to `setup.sh` (`zzbot diagnose`), giving administrators immediate root-cause visibility through structured PASS/WARN/FAIL audits, automated crash-log scraping, safe auto-remediation, and sanitized log export.

## Goals

1. **Main Menu & Submenu Navigation**:
   - Add option `15) Run Diagnostic Health Check` to `setup.sh` main menu, updating selection prompt bounds to `[0-15]`.
   - Selecting 15 opens a dedicated submenu with 7 categories, "Run All Checks", and "Back to Main Menu".
2. **CLI & Headless Support**:
   - `zzbot diagnose`: launches interactive submenu.
   - `zzbot diagnose all`: runs the full diagnostic suite directly.
   - `zzbot diagnose [host|containers|db|web|ssl|telegram]`: runs a targeted category check.
   - `--fix`: runs safe, non-destructive automated remediation without prompting.
   - `--export`: saves an anonymized diagnostic report to `/tmp/zzbot-diagnose-<timestamp>.log`.
   - Returns exit code `0` on clean/warnings, `1` if any test fails.
3. **Subsystem Diagnostic Checks**:
   - **Host & System**: Free disk space (`/` and `/srv`), inode limits (`df -i`), RAM & swap availability (`free -m`), and port 80/443 conflicts (`netstat` / `ss` / `lsof`).
   - **Docker Containers**: Verify status of `zzpanel_app`, `zzpanel_mysql`, `zzpanel_nginx`, `zzpanel_worker`, `zzpanel_certbot`, `zzpanel_backup`, `zzpanel_phpmyadmin`; inspect Docker health status, OOMKilled flags, exit codes, and scrape last 15 lines of container logs on failure.
   - **Database & Migrations**: MySQL ping (`mysqladmin ping`), credentials test via containerized PHP, connection saturation (`Threads_connected` vs `max_connections`), and unapplied migration check (`sql/migrations/*.sql` vs `schema_migrations`).
   - **Web Server & PHP-FPM**: Verify Apache2 and PHP-FPM running inside `zzpanel_app`, loopback HTTP response (`curl -s -o /dev/null -w "%{http_code}" http://localhost/ZZPanelBot/index.php`) distinguishing 200 (OK) vs 423 (database unreachable) vs 500 (PHP crash), directory write permissions for `logs` and `temp` (`www-data`), APCu cache status, and inline application error log tail.
   - **Domain, SSL & Reverse Proxy**: Validate Nginx syntax (`docker exec zzpanel_nginx nginx -t`), SSL certificate expiration countdown (`openssl x509 -enddate`), public DNS resolution vs host public IP, and loopback HTTPS proxy probe.
   - **Telegram Network & Webhook**: 4-stage probe distinguishing DNS resolution, TCP/TLS egress reachability (identifying Iranian censorship or firewall packet drops), bot token authentication (`/getMe`), and webhook status (`/getWebhookInfo` extracting `pending_update_count`, `last_error_message`, and `last_error_date`).
4. **Safe Auto-Remediation**:
   - Prompt at end of interactive check (or automatically with `--fix`) to fix safe issues:
     - Reset file and directory permissions on `logs` and `temp` to `www-data:www-data` (0770).
     - Reset Telegram webhook with `drop_pending_updates=true` to flush update poison-pills.
     - Restart stopped or unhealthy containers via `docker compose restart <service>`.
5. **Sanitized Support Bundle**:
   - Export option masks `BOT_TOKEN`, `DB_PASS`, and `MYSQL_ROOT_PASSWORD` before writing to `/tmp/zzbot-diagnose-<timestamp>.log`.

## Non-Goals

- Automatically applying database migrations during auto-remediation (database schema changes remain deliberate administrator actions).
- Automatically reconfiguring server DNS or external router/firewall rules.
- Altering application source code or PHP business logic.

## Anti-Goals

- Executing destructive operations or container deletions (`docker compose down -v`) during diagnostics or auto-remediation.
- Halting script execution on individual check failures; all checks in a selected category must run to completion.
- Exposing plain-text secrets, bot tokens, or database passwords in output logs or terminal summaries.

## Constraints

- Self-contained implementation inside `setup.sh` (Approach A).
- Compatible with Ubuntu 20.04+ bash environments and Docker Compose v2 (`docker compose`) or v1 (`docker-compose`).
- Graceful degradation when optional tools (`jq`, `ss`, `netstat`) are missing, falling back to python3, grep, or procfs.
- All network curl requests must enforce strict timeouts (max 5s) to prevent the diagnostic tool from hanging.

## Approaches

1. **Approach A: Monolithic Functions inside `setup.sh` (Chosen)**
   - Implement diagnostic routines directly within `setup.sh`.
   - *Pros*: Standalone, portable, works even if repository files in `/srv` are corrupted, moved, or missing.
   - *Trade-off*: Increases size of `setup.sh` by ~350 lines.

2. **Approach B: External Helper Script (`docker/scripts/diagnose.sh`)**
   - Place diagnostics in an external script called by `setup.sh`.
   - *Pros*: Keeps `setup.sh` smaller.
   - *Trade-off*: Fails if `/srv` files are inaccessible or during partial updates.

## What Was Decided

- Approach A selected.
- 7 diagnostic categories + "Run All Checks" option.
- Interactive submenu displayed first by default; direct CLI execution supported.
- Non-destructive read-only checks first; auto-remediation prompted only when recoverable issues are detected.
- Censorship-aware Telegram diagnostic distinguishing DNS poisoning from TLS IP blocking.
- Credential-redacted diagnostic export to `/tmp/zzbot-diagnose-<timestamp>.log`.

## What Is Left to the Builder

- Implement diagnostic functions in `setup.sh`:
  - Output formatting helpers (`DIAG_PASS`, `DIAG_WARN`, `DIAG_FAIL`).
  - `diagnose_host()`
  - `diagnose_containers()`
  - `diagnose_db()`
  - `diagnose_web()`
  - `diagnose_ssl()`
  - `diagnose_telegram()`
  - `diagnose_all()`
  - `apply_auto_fixes()`
  - `diagnose_menu()`
  - CLI argument dispatching for `zzbot diagnose ...`
- Update main menu to include `15) Run Diagnostic Health Check`.
- Add unit tests verifying diagnostic argument parsing, redaction logic, and failure-handling.
