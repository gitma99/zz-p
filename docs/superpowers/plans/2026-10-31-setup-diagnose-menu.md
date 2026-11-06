# Setup.sh Diagnostic Health Check Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a comprehensive diagnostic health check suite (`zzbot diagnose`) and main menu option (`15`) to `setup.sh` that identifies root causes across host resources, Docker containers, MySQL, Apache/PHP-FPM, Nginx/SSL, and Telegram API/webhook connectivity, with safe auto-remediation and sanitized export.

**Architecture:** Monolithic diagnostic functions directly in `setup.sh` (Approach A) for standalone portability. Each subsystem is encapsulated in its own diagnostic function (`diagnose_host`, `diagnose_containers`, `diagnose_db`, `diagnose_web`, `diagnose_ssl`, `diagnose_telegram`), coordinated by a central runner (`diagnose_all`), an interactive submenu (`diagnose_menu`), and a CLI argument dispatcher supporting `--fix` and `--export`.

**Tech Stack:** Bash (4.0+), Docker CLI / Docker Compose, cURL, OpenSSL, Python3 / jq fallback.

**Spec:** `docs/superpowers/specs/2026-10-31-setup-diagnose-menu-design.md`

## Global Constraints

- Standalone implementation inside `setup.sh` with zero external bash file dependencies.
- Shell safety: `set -euo pipefail` compatible; individual test failures must never cause `setup.sh` to exit prematurely.
- Main menu option `15) Run Diagnostic Health Check` with menu prompt updated to `[0-15]`.
- Exit codes: `0` for clean run or warnings only, `1` if any test fails.
- Secrets masking: `BOT_TOKEN`, `DB_PASS`, `MYSQL_ROOT_PASSWORD` must be redacted in terminal and export files.
- Safe auto-remediation: only repairs permissions, clears webhook backlog with `drop_pending_updates=true`, and restarts failed containers. Never auto-applies migrations or deletes data.

## Review Focus

- Missing `.env` or missing environment variables (`DOMAIN`, `BOT_TOKEN`, `DB_USER`): report FAIL cleanly and skip dependent tests without unbound variable crashes.
- Docker daemon stopped or socket inaccessible: report FAIL on container checks and continue without crashing.
- Telegram DNS failure / censorship: distinguish DNS poisoning from TLS IP blocking and provide VPS network troubleshooting advice without throwing unhandled curl errors.
- Non-interactive headless execution (`--fix` without TTY): run auto-remediation without blocking on `read -r -p`.
- Secret redaction with special characters: ensure tokens containing colons or passwords with regex characters are safely masked in log export.

---

### Task 1: Diagnostic Formatting, Status Tracking, Secret Redaction & Export Utilities

**Files:**
- Modify: `setup.sh` (diagnostic helper section)
- Test: `tests/unit/setup_diagnose_test.sh`

**Interfaces:**
- Produces:
  - `DIAG_PASS(message: string)`
  - `DIAG_WARN(message: string)`
  - `DIAG_FAIL(message: string)`
  - `DIAG_NOTE(message: string)`
  - `mask_secret(raw: string, secret: string) -> string`
  - `init_diagnose_state()`
  - `export_diagnose_report(target_file: string)`
  - Global variables: `DIAG_HAS_FAIL`, `DIAG_HAS_WARN`, `AUTO_FIX_CANDIDATES`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/setup_diagnose_test.sh` with tests for output helpers, status counters, secret masking, and export file writing:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Test harness for setup.sh diagnostic suite
source <(sed -n '/# --- DIAGNOSTIC HELPERS START ---/,/# --- DIAGNOSTIC HELPERS END ---/p' setup.sh)

# Test 1: Status tracking
init_diagnose_state
DIAG_PASS "Pass test"
DIAG_WARN "Warn test"
[[ "$DIAG_HAS_WARN" -eq 1 ]] || { echo "FAIL: DIAG_HAS_WARN not set"; exit 1; }
[[ "$DIAG_HAS_FAIL" -eq 0 ]] || { echo "FAIL: DIAG_HAS_FAIL wrongly set"; exit 1; }
DIAG_FAIL "Fail test"
[[ "$DIAG_HAS_FAIL" -eq 1 ]] || { echo "FAIL: DIAG_HAS_FAIL not set"; exit 1; }

# Test 2: Secret masking
masked=$(mask_secret "token 123456:TEST_BOT_TOKEN_MOCK_XYZ in text" "123456:TEST_BOT_TOKEN_MOCK_XYZ")
[[ "$masked" == *"123456:***"* ]] || { echo "FAIL: secret token was not properly masked"; exit 1; }

echo "PASS: Task 1 tests"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: FAIL with missing markers or undefined functions.

- [ ] **Step 3: Implement diagnostic helpers in `setup.sh`**

Add `# --- DIAGNOSTIC HELPERS START ---` block to `setup.sh` with:
- `init_diagnose_state()` initializing `DIAG_HAS_FAIL=0`, `DIAG_HAS_WARN=0`, `AUTO_FIX_CANDIDATES=()`, and `DIAG_LOG_BUFFER=""`.
- Colorized output functions: `DIAG_PASS` (`[PASS]`), `DIAG_WARN` (`[WARN]`), `DIAG_FAIL` (`[FAIL]`), `DIAG_NOTE` (`[INFO]`).
- Appending every output to `DIAG_LOG_BUFFER`.
- `mask_secret(text, secret)` replacing secret values with sanitized placeholders (e.g., keeping prefix/length and masking body).
- `export_diagnose_report(target_file)` saving sanitized `DIAG_LOG_BUFFER` to disk.

- [ ] **Step 4: Run test to verify it passes**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: `PASS: Task 1 tests`

- [ ] **Step 5: Commit**

```bash
git add setup.sh tests/unit/setup_diagnose_test.sh
git commit -m "feat(diagnose): add diagnostic formatting, status tracking, and redaction helpers"
```

---

### Task 2: Host & System Resources and Docker Container Diagnostics

**Files:**
- Modify: `setup.sh`
- Test: `tests/unit/setup_diagnose_test.sh`

**Interfaces:**
- Consumes: `DIAG_PASS`, `DIAG_WARN`, `DIAG_FAIL`, `DIAG_NOTE`, `AUTO_FIX_CANDIDATES`
- Produces:
  - `diagnose_host()`
  - `diagnose_containers()`
  - `scrape_container_crash_logs(container_name: string, max_lines: int)`

- [ ] **Step 1: Write the failing test**

Add unit tests to `tests/unit/setup_diagnose_test.sh` testing:
- `diagnose_host` disk threshold warning/failure logic (simulating df output).
- `diagnose_containers` parsing container running states, healthcheck statuses, OOMKilled flag, and crash exit codes.
- Verifying stopped/unhealthy containers are registered into `AUTO_FIX_CANDIDATES`.

- [ ] **Step 2: Run test to verify it fails**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: FAIL with `diagnose_host: command not found`.

- [ ] **Step 3: Implement `diagnose_host` and `diagnose_containers` in `setup.sh`**

- `diagnose_host`:
  - Check disk free space on `/` and `$BASE_DIR`: FAIL if < 1GB, WARN if < 3GB.
  - Check inode usage: WARN if > 90% full.
  - Check available RAM: WARN if available RAM < 256MB.
  - Check ports 80 and 443: use `ss`, `netstat`, or `lsof` to ensure ports are either bound by Docker or free (WARN if bound by non-docker process like host apache2/nginx).
- `diagnose_containers`:
  - Verify Docker daemon connectivity (`docker info >/dev/null 2>&1`). FAIL if down and return.
  - Check stack containers: `zzpanel_app`, `zzpanel_mysql`, `zzpanel_nginx`, `zzpanel_worker`, `zzpanel_certbot`, `zzpanel_backup`, `zzpanel_phpmyadmin`.
  - For each container: inspect `State.Status`, `State.Health.Status`, `State.OOMKilled`, and `State.ExitCode`.
  - If a container is stopped or unhealthy: call `scrape_container_crash_logs` to print last 15 lines of log inline, and append service name to `AUTO_FIX_CANDIDATES`.

- [ ] **Step 4: Run test to verify it passes**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add setup.sh tests/unit/setup_diagnose_test.sh
git commit -m "feat(diagnose): implement host resources and docker container checks"
```

---

### Task 3: Database & Schema Migrations and Web Server / PHP-FPM Diagnostics

**Files:**
- Modify: `setup.sh`
- Test: `tests/unit/setup_diagnose_test.sh`

**Interfaces:**
- Consumes: `DIAG_PASS`, `DIAG_WARN`, `DIAG_FAIL`, `DIAG_NOTE`, `AUTO_FIX_CANDIDATES`, `BASE_DIR`
- Produces:
  - `diagnose_db()`
  - `diagnose_web()`

- [ ] **Step 1: Write the failing test**

Add unit tests to `tests/unit/setup_diagnose_test.sh` verifying:
- `diagnose_db` detection of database authentication, connection pool saturation, and detection of unapplied files in `sql/migrations/`.
- `diagnose_web` checking loopback HTTP response codes (200 PASS vs 423 FAIL with DB lock notice vs 500 FAIL), checking directory permissions on `logs` and `temp`, and adding permission fixes to `AUTO_FIX_CANDIDATES`.

- [ ] **Step 2: Run test to verify it fails**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: FAIL with `diagnose_db: command not found`.

- [ ] **Step 3: Implement `diagnose_db` and `diagnose_web` in `setup.sh`**

- `diagnose_db`:
  - Check `.env` database variables (`DB_NAME`, `DB_USER`, `DB_PASS`).
  - Ping MySQL via `docker exec zzpanel_mysql mysqladmin ping -h localhost -u root -p"$MYSQL_ROOT_PASSWORD"` (or root password from `.env`).
  - Check credentials connection via `docker exec zzpanel_app php -r "..."`.
  - Query connection saturation: `SHOW GLOBAL STATUS LIKE 'Threads_connected'` vs `SHOW GLOBAL VARIABLES LIKE 'max_connections'`.
  - Query applied migrations from `schema_migrations` table and compare with `sql/migrations/*.sql` on host. Report WARN with missing migration filenames if unapplied migrations exist.
- `diagnose_web`:
  - Check Apache and PHP7.4-FPM processes inside `zzpanel_app` (`service php7.4-fpm status` and `apache2ctl -S`).
  - Probing HTTP loopback inside `zzpanel_app`: `curl -s -o /dev/null -w "%{http_code}" http://localhost/ZZPanelBot/index.php`.
    - If 200: PASS.
    - If 423: FAIL ("Bot locked: database connection failure in config.php").
    - If 500: FAIL ("PHP fatal error or syntax crash").
    - Scrape last 15 lines of `$BASE_DIR/logs/app/error.log` on failure.
  - Check write permissions on `$BASE_DIR/logs` and `$BASE_DIR/data`: FAIL if not writable by `www-data` (UID 33) and register permission repair in `AUTO_FIX_CANDIDATES`.
  - Check APCu cache extension inside `zzpanel_app` (`php -r "echo extension_loaded('apcu') ? 'yes' : 'no';"`).

- [ ] **Step 4: Run test to verify it passes**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add setup.sh tests/unit/setup_diagnose_test.sh
git commit -m "feat(diagnose): implement database and web server runtime diagnostic checks"
```

---

### Task 4: Domain, SSL, Reverse Proxy, and Telegram Connectivity & Webhook Diagnostics

**Files:**
- Modify: `setup.sh`
- Test: `tests/unit/setup_diagnose_test.sh`

**Interfaces:**
- Consumes: `DIAG_PASS`, `DIAG_WARN`, `DIAG_FAIL`, `DIAG_NOTE`, `AUTO_FIX_CANDIDATES`, `BASE_DIR`
- Produces:
  - `diagnose_ssl()`
  - `diagnose_telegram()`

- [ ] **Step 1: Write the failing test**

Add unit tests to `tests/unit/setup_diagnose_test.sh` verifying:
- `diagnose_ssl` certificate expiry calculation (days remaining), Nginx config syntax check, and public IP vs DNS resolution check.
- `diagnose_telegram` 4-stage checks:
  1. DNS resolution for `api.telegram.org` (detects DNS poisoning).
  2. TCP/TLS connection handshake to `api.telegram.org:443` (detects IP drop/censorship).
  3. `getMe` token validation.
  4. `getWebhookInfo` status check: extracting `url`, `pending_update_count`, `last_error_message`, and adding webhook reset to `AUTO_FIX_CANDIDATES` when error or high backlog exists.

- [ ] **Step 2: Run test to verify it fails**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: FAIL with `diagnose_ssl: command not found`.

- [ ] **Step 3: Implement `diagnose_ssl` and `diagnose_telegram` in `setup.sh`**

- `diagnose_ssl`:
  - Validate Nginx config syntax: `docker exec zzpanel_nginx nginx -t`.
  - Validate SSL certificate existence at `$BASE_DIR/data/certbot/conf/live/${DOMAIN}/fullchain.pem`.
  - Check certificate expiry using `openssl x509 -enddate -noout -in ...` and compute days remaining. WARN if < 15 days, FAIL if expired.
  - Resolve `$DOMAIN` via DNS (`dig +short`, `nslookup`, or `getent ahosts`) and compare against server public IP (`curl -s4 https://api.ipify.org` or similar). WARN if mismatch.
  - Probe loopback HTTPS through Nginx: `curl -sk -H "Host: ${DOMAIN}" https://127.0.0.1/ZZPanelBot/index.php`.
- `diagnose_telegram`:
  - Stage 1 (DNS): probe `api.telegram.org` resolution. If fails, report FAIL with "DNS resolution failed - DNS poisoning or nameserver issue".
  - Stage 2 (Egress Handshake): probe `curl -s -m 5 --connect-timeout 5 -I https://api.telegram.org`. If fails while DNS succeeded, report FAIL with "TCP/TLS handshake to Telegram blocked - Likely firewall or national censorship/DPI packet drop".
  - Stage 3 (Token Auth): call `https://api.telegram.org/bot${BOT_TOKEN}/getMe`. If returns `ok: false`, report FAIL with "Invalid BOT_TOKEN in .env".
  - Stage 4 (Webhook Info): call `https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo`.
    - Check configured `url` matches `https://${DOMAIN}/ZZPanelBot/index.php`.
    - Check `pending_update_count`. If > 50: WARN with update backlog count.
    - Check `last_error_message` and `last_error_date`. If present: WARN/FAIL with exact Telegram delivery error message (e.g. 502 Bad Gateway, SSL error).
    - Register webhook reset in `AUTO_FIX_CANDIDATES`.

- [ ] **Step 4: Run test to verify it passes**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add setup.sh tests/unit/setup_diagnose_test.sh
git commit -m "feat(diagnose): implement domain/ssl and telegram 4-stage network diagnostics"
```

---

### Task 5: Auto-Remediation, Main Menu & Submenu Navigation, CLI Dispatching & Verification

**Files:**
- Modify: `setup.sh`
- Test: `tests/unit/setup_diagnose_test.sh`

**Interfaces:**
- Consumes: All diagnostic functions from Tasks 1-4
- Produces:
  - `apply_auto_fixes(interactive: bool)`
  - `diagnose_all(auto_fix: bool, export_report: bool)`
  - `diagnose_menu()`
  - Main menu item `15` and CLI dispatch `zzbot diagnose ...`

- [ ] **Step 1: Write the failing test**

Add tests to `tests/unit/setup_diagnose_test.sh` verifying:
- CLI argument parsing: `diagnose`, `diagnose all`, `diagnose all --fix`, `diagnose host`, `diagnose --export`.
- `apply_auto_fixes` applying permissions, webhook reset with `drop_pending_updates=true`, and container restarts.
- Exit code returning 0 for pass/warn and 1 for failure.
- Main menu output string contains `15) Run Diagnostic Health Check` and prompt `[0-15]`.

- [ ] **Step 2: Run test to verify it fails**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: FAIL with `diagnose_all: command not found` or menu string mismatch.

- [ ] **Step 3: Implement Auto-Fixes, Menus, and CLI Dispatch in `setup.sh`**

- `apply_auto_fixes(interactive)`:
  - If `AUTO_FIX_CANDIDATES` is empty, notify admin and return.
  - If interactive, prompt: "Found N recoverable issue(s). Do you want to apply safe auto-fixes now? (y/N)".
  - Perform candidate fixes:
    - Permissions: `chown -R www-data:www-data "$BASE_DIR/logs" "$BASE_DIR/data"` and `chmod -R 770 ...`.
    - Webhook: `curl -s -F "url=https://${DOMAIN}/ZZPanelBot/index.php" "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?drop_pending_updates=true"`.
    - Unhealthy/stopped containers: `${DOCKER_COMPOSE_CMD[@]} restart <service>`.
- `diagnose_all`:
  - Run `diagnose_host`, `diagnose_containers`, `diagnose_db`, `diagnose_web`, `diagnose_ssl`, `diagnose_telegram`.
  - Print summary box with total PASS / WARN / FAIL counts.
  - Trigger `apply_auto_fixes`.
  - If `export_report` is true, write to `/tmp/zzbot-diagnose-$(date +%s).log`.
  - Return 0 if `DIAG_HAS_FAIL == 0`, else return 1.
- `diagnose_menu()`:
  - Submenu loop with options:
    `1) Run All Checks`
    `2) Host & System Resources`
    `3) Docker Containers & Health`
    `4) Database & Schema Migrations`
    `5) Web Server & PHP-FPM Runtime`
    `6) Domain, SSL & Reverse Proxy`
    `7) Telegram Network & Webhook`
    `0) Back to Main Menu`
- Update `show_menu`:
  - Add `15) Run Diagnostic Health Check`
  - Update prompt `Enter choice [0-15]: `
  - Add `15) diagnose_menu || true ;;` to case switch.
- Update CLI entrypoint:
  - Add `diagnose)` case handling sub-arguments (`all`, `host`, `containers`, `db`, `web`, `ssl`, `telegram`, `--fix`, `--export`).

- [ ] **Step 4: Run test to verify it passes**

Run: `bash tests/unit/setup_diagnose_test.sh`
Expected: PASS

- [ ] **Step 5: Verify syntax and manual run**

Run: `bash -n setup.sh`
Expected: Clean syntax with exit code 0.

- [ ] **Step 6: Commit**

```bash
git add setup.sh tests/unit/setup_diagnose_test.sh
git commit -m "feat(diagnose): add auto-remediation, diagnose submenu, and main menu integration"
```

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-10-31-setup-diagnose-menu.md`. Please review the plan. Which execution approach would you prefer?

- **Subagent-driven** - A fresh subagent implements each task and a fresh reviewer checks it before the next one starts, then a whole-branch review at the end. Most thorough; costs a fresh context per task and per review.
- **Native** - I implement every task myself in this session, the way this harness runs work, then one fresh reviewer on the most capable model checks the whole branch. Cheapest and fastest; no independent review until the end. Runs well with a mid-tier session model, since the plan carries the design.

For this plan I recommend **Native**, because all tasks modify a single script (`setup.sh`) and its test file, and maintaining sequential context in one session avoids overhead and merge conflicts across subagents. Does the plan capture what you want, and which approach should we use?
