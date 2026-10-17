# MySQL Spike Flight Recorder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record MySQL spikes on 1 vCPU at ~1% idle so a 2-minute bot hang can be diagnosed 20 minutes later from files + on-demand graphs.

**Architecture:** Slow log + digest counters always on; 5s sampler writes cheap metrics and opens a `spike-<ts>/` folder only when overloaded; PHP logs only slow webhooks; Sentry (5% sampled, SaaS) gives span-level app truth; Grafana stays stopped until needed behind nginx `/grafana/`; test scaffolding (PHPUnit ^9.6, CI-only, never in prod image) verifies each layer.

**Tech Stack:** MySQL 8.0 slow log + `performance_schema`, `prom/mysqld-exporter:v0.15`, `prom/prometheus:v2.52`, `grafana/grafana:10.4`, bash, PHP (mysqli), nginx, Sentry SaaS free (5% traces, 0% profiles), PHPUnit ^9.6 (dev-only, CI).

**Spec:** This conversation (no separate spec doc): 1 vCPU constraint, 300MB RAM headroom OK, tools ship in install/update (no separate install command), reset archives before truncating, Grafana via nginx subpath.

## Global Constraints

- 1 vCPU host — no 1s polling; all intervals at 5s, slow threshold >= 2s.
- Idle CPU budget ~2-3% at 5s cadence; Grafana stopped by default (`profiles: [observe]`).
- `MONITOR_DIR=logs/monitor`, `ARCHIVE_DIR=logs/monitor/archive`, `MYSQL="docker compose exec -T mysql mysql"`.
- Spike trigger: `THREADS_RUNNING > 20`.
- Reset never deletes: snapshot → `pre-reset-<ts>.tar.gz` → truncate.
- Follow `setup.sh` style: `INFO/WARN/ERROR`, `require_installed`, `DOCKER_COMPOSE_CMD`.
- Tests: PHPUnit ^9.6 (PHP 7.4-compatible), CI-only — only `tests/`, `phpunit.xml` stay out of the image (`.dockerignore`). Exception: `vendor/` + `composer.lock` SHIP because the Sentry SDK is a runtime dependency — Dockerfile gains one `composer install --no-dev` layer (Task 6). `composer test` runs ONE runner: `vendor/bin/phpunit` (unit + arch suites) + smoke scripts (`bash -n setup.sh`, shellcheck, compose `config -q` on both files). `tools/logger_selfcheck.php` is fully migrated into the suites and deleted in the same task (no dual test system).
- Monitoring users: `monitor` (SELECT on `performance_schema.*` incl. digest DELETE for TRUNCATE, + PROCESS) and `exporter` (SELECT, PROCESS, REPLICATION CLIENT) created idempotently in `sql/migrations/2026-10-16-monitor-users.sql` (Task 1); `MYSQL` helpers auth as monitor, digest reset runs as root.
- Canonical monitor dir: host `./logs/monitor` is mounted at `/logs/monitor` (recorder, backup) and `/var/www/html/ZZPanelBot/logs/monitor` (app) — every writer lands in the same host dir; no other monitor path may be written.
- mysqld-exporter v0.15+: `DATA_SOURCE_NAME` was REMOVED upstream — auth only via mounted `.my.cnf` + `--mysqld.address/--mysqld.username`.
- Recorder launch: compose service `recorder` runs the 5s loop (`bash /scripts/mysql-recorder.sh; sleep 5`) so sampling exists in prod without cron.
- PHP 7.4 only: no nullsafe operator (`?->`), no PHP 8 syntax anywhere.

## Review Focus

- Slow-log path not writable → MySQL fails to start; expect container healthy + `slow_query_log=ON`.
- Grafana assets 404 on subpath → expect `SERVE_FROM_SUB_PATH=true` + `ROOT_URL=.../grafana/`.
- `TRUNCATE performance_schema...` needs privilege → expect exporter/admin user has it or reset reports error, not silent pass.
- Prometheus retention fills disk → expect `--storage.tsdb.retention.time=7d` + size cap.
- Spike sampler races (two captures same second) → expect `mkdir -p` + unique `date +%s` dir, second run reuses it.
- Recorder loop dead (crashed bash, missing `/logs/monitor` mount) → expect `metrics.log` growing 1 line/5s; `observe_status` surfaces this first.
- Exporter auth wrong (`.my.cnf` placeholder not substituted) → expect Prometheus target `down` in `/api/v1/targets`, not exporter crash-looping.

---

## Background: What We Are Debugging

`bt hang 2min` = Telegram bot (`index.php` webhooks) unresponsive ~120s, matching `max_execution_time=120` (`docker/php/botpanel-perf.ini:14`) and `set_time_limit(120)` (`index.php:36-47`). MySQL CPU spike can be cause or effect.

Ranked hypotheses from code:

1. Missing secondary indexes — `sql/sql.php` creates only PKs. Hot queries with no index: `config.php:92` users lookup, `service_overview.php:14` orders scan, `index.php:796` list query.
2. Marzban panel stall holding PHP 60s (`App/apis/marzban-api/api.php:320`) + Telegram retries → looks like MySQL CPU.
3. `service_overview.php:126` DDL (`CREATE TABLE IF NOT EXISTS orders_archive`) causing metadata locks.
4. Backup `mysqldump` (`docker/backup/backup.sh:141`) without `--single-transaction` and broadcast `SELECT * FROM users` (`send.php:16`).

---

## File Map

| File | Change |
|---|---|
| `docker/mysql/lowcpu.cnf` | Create (Task 1) |
| `docker-compose.yml` (mysql, recorder, exporter, prometheus, grafana + app/backup monitor mounts) | Modify (Task 1) |
| `docker/prometheus/prometheus.yml` | Create (Task 1, 5s scrape) |
| `docker/mysqld-exporter/my.cnf` + `sql/migrations/2026-10-16-monitor-users.sql` | Create — exporter auth + monitor/exporter users (Task 1) |
| `docker/grafana/provisioning/datasources/prometheus.yml` | Create — datasource auto-provision (Task 1) |
| `.env.example` (`MONITOR_PASS`, `EXPORTER_PASS`) | Modify (Task 1) |
| `Dockerfile` (composer install layer) | Modify — vendored Sentry SDK ships in image (Task 6) |
| `docker/scripts/mysql-recorder.sh` | Create (Task 2, 5s cadence) |
| `tools/spike-report.sh` | Create (Task 2, window mode) |
| `config.php` (after `$sql` connect) | Modify — slow-only log (Task 3) |
| `docker/backup/backup.sh`, `send.php` | Modify — start markers (Task 3) |
| `docker/nginx/nginx.conf.template` | Modify — `/grafana/` block (Task 4) |
| `setup.sh` (menu + helpers) | Modify — debug submenu + investigate (Task 5) |
| `install.sh` / `update.sh` | Modify — ship observability by default (Task 1) |
| `config.php` + `App/apis/marzban-api/api.php` | Modify — Sentry init + span wraps (Task 6) |
| `config.php`, `App/functions/service_overview.php`, `workers/task_worker.php`, `send.php`, `custom.php`, `App/apis/marzban-api/api.php`, Telegram `api/*.php` | Modify — log points, INFO/WARNING/ERROR only, no ms change (Task 7) |
| `.env.example` | Modify — `SENTRY_DSN`, `SENTRY_ENVIRONMENT`, sample rates (Task 1 already adds `MONITOR_PASS`/`EXPORTER_PASS`) |
| `composer.json`, `phpunit.xml`, `tests/` (unit + Fakes + integration), `tests/fixtures/spike-sample/` | Create — test scaffolding (Task 8) |
| `App/Utils/SpikeVerdict.php`, `App/Utils/SentryGateway.php`, `App/Utils/handlers/SentryHandler.php` (seam), `tools/spike-report.sh` (thin wrapper) | Modify — testability seams only, no behavior change (Task 8) |
| `tools/logger_selfcheck.php` | Delete — all 22 checks migrated into `tests/unit` + `tests/arch` (Task 8) |
| `.github/workflows/ci.yml` (add `test` job), `.dockerignore` | Modify — CI runs tests, image stays lean (Task 8) |
| `sql/sql.php` + `sql/migrations/*` | Modify — indexes only (Task 9, after monitor reviewed) |

---

### Task 1: MySQL recording + compose services

**Files:**
- Create: `docker/mysql/lowcpu.cnf`
- Create: `docker/mysqld-exporter/my.cnf` + `sql/migrations/2026-10-16-monitor-users.sql`
- Create: `docker/grafana/provisioning/datasources/prometheus.yml`
- Modify: `docker-compose.yml` (drop duplicate mysql `command:`; recorder service; app/backup/monitor mounts)
- Modify: `.env.example` (add `MONITOR_PASS`, `EXPORTER_PASS`)
- Create: `docker/prometheus/prometheus.yml`

**Interfaces:**
- Consumes: nothing
- Produces: `slow.log` at `logs/mysql/slow.log`; metrics at `prometheus:9090`; users `monitor`/`exporter` (consumed by Tasks 2/5 via `MONITOR_PASS`); canonical host dir `logs/monitor` (consumed by Tasks 2/3/5)
- Produces: `recorder` service appending `metrics.log` every 5s with trigger `THREADS_RUNNING > 20` writing `spike-<ts>/` folders

```ini
# docker/mysql/lowcpu.cnf
[mysqld]
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
log_queries_not_using_indexes = 0
performance-schema = ON
performance-schema-consumer-events-statements-history = OFF
performance-schema-consumer-events-waits-history = OFF
innodb-buffer-pool-size = 256M
max-connections = 60
```

```yaml
# docker-compose.yml (excerpt)
mysql:
  image: mysql:8.0
  # tuning lives ONLY in lowcpu.cnf — never duplicate the values via command: (drift risk)
  volumes:
    - ./data/mysql:/var/lib/mysql
    - ./docker/mysql/lowcpu.cnf:/etc/mysql/conf.d/lowcpu.cnf:ro
    - ./logs/mysql:/var/log/mysql

# canonical monitor-dir mounts, added to the existing services:
#   app:    ./logs/monitor:/var/www/html/ZZPanelBot/logs/monitor
#   backup: ./logs/monitor:/logs/monitor

mysqld-exporter:
  image: prom/mysqld-exporter:v0.15   # v0.15 REMOVED DATA_SOURCE_NAME — .my.cnf + flags only
  entrypoint: ["/bin/sh", "-c"]
  command:
    - "sed \"s/__EXPORTER_PASS__/$${EXPORTER_PASS}/g\" /etc/exp/my.cnf > /tmp/my.cnf && exec /bin/mysqld_exporter --config.my-cnf=/tmp/my.cnf --mysqld.address=mysql:3306 --mysqld.username=exporter --collect.global_status --collect.info_schema.innodb_metrics --collect.info_schema.processlist --no-collect.info_schema.tables --no-collect.info_schema.tablestats"
  environment:
    - EXPORTER_PASS=${EXPORTER_PASS}
  volumes:
    - ./docker/mysqld-exporter/my.cnf:/etc/exp/my.cnf:ro

recorder:
  image: mysql:8.0            # reuse image: has bash + mysql client, zero extra pull
  entrypoint: []
  command: ["bash", "-c", "while :; do bash /scripts/mysql-recorder.sh; sleep 5; done"]
  environment:
    - MONITOR_HOST=mysql
    - MONITOR_USER=monitor
    - MONITOR_PASS=${MONITOR_PASS}
    - DB_NAME=${DB_NAME}
  volumes:
    - ./docker/scripts:/scripts:ro
    - ./logs/monitor:/logs/monitor
  restart: unless-stopped
  logging: { driver: json-file, options: { max-size: "10m", max-file: "1" } }
  depends_on: { mysql: { condition: service_healthy } }

prometheus:
  image: prom/prometheus:v2.52
  command:
    - --config.file=/etc/prometheus/prometheus.yml
    - --storage.tsdb.retention.time=7d
    - --storage.tsdb.retention.size=1GB
  volumes:
    - ./docker/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro
    - ./logs/monitor/prom-data:/prometheus

grafana:
  image: grafana/grafana:10.4
  profiles: ["observe"]
  environment:
    - GF_SERVER_ROOT_URL=https://${DOMAIN}/grafana/   # %()s is ini-file templating; env vars need $VAR
    - GF_SERVER_SERVE_FROM_SUB_PATH=true
    - GF_AUTH_ANONYMOUS_ENABLED=false
  volumes:
    - ./docker/grafana/provisioning:/etc/grafana/provisioning:ro
```

```yaml
# docker/prometheus/prometheus.yml
global:
  scrape_interval: 5s
  scrape_timeout: 4s
scrape_configs:
  - job_name: mysql
    static_configs:
      - targets: [mysqld-exporter:9104]
```

```ini
# docker/mysqld-exporter/my.cnf (EXPORTER_PASS sed-substituted at container start)
[client]
user = exporter
password = __EXPORTER_PASS__
```

```sql
-- sql/migrations/2026-10-16-monitor-users.sql (idempotent; run once per deployment)
-- placeholders substituted by setup.sh: sed "s/__MONITOR_PASS__/${MONITOR_PASS}/;s/__EXPORTER_PASS__/${EXPORTER_PASS}/" then piped to root
CREATE USER IF NOT EXISTS 'monitor'@'%' IDENTIFIED BY '__MONITOR_PASS__';
GRANT SELECT, DELETE ON performance_schema.* TO 'monitor'@'%';   -- DELETE enables digest TRUNCATE
GRANT PROCESS ON *.* TO 'monitor'@'%';
CREATE USER IF NOT EXISTS 'exporter'@'%' IDENTIFIED BY '__EXPORTER_PASS__';
GRANT SELECT, PROCESS, REPLICATION CLIENT ON *.* TO 'exporter'@'%';
```

```yaml
# docker/grafana/provisioning/datasources/prometheus.yml
apiVersion: 1
datasources:
  - name: prometheus
    type: prometheus
    url: http://prometheus:9090
    isDefault: true
```

- [ ] **Step 1: Add config files, monitor-users migration, compose services (incl. recorder loop)**
- [ ] **Step 2: Apply monitor-users migration** — Run: `sed "s/__MONITOR_PASS__/${MONITOR_PASS}/;s/__EXPORTER_PASS__/${EXPORTER_PASS}/" sql/migrations/2026-10-16-monitor-users.sql | docker compose exec -T mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD}"` Expected: no error; second run also exits 0 (idempotent)
- [ ] **Step 3: Verify** — Run: `docker compose up -d mysql recorder mysqld-exporter prometheus && sleep 15 && docker compose exec -T mysql mysql -umonitor -p"${MONITOR_PASS}" -e "SHOW VARIABLES LIKE 'slow_query_log'" && docker compose exec -T prometheus sh -c 'wget -qO- http://prometheus:9090/api/v1/targets' | grep -c '"health":"up"'` Expected: `ON`; targets-up count `>= 2` (mysqld-exporter + prometheus self)
- [ ] **Step 4: Verify recorder loop** — Run: `sleep 15 && wc -l logs/monitor/metrics.log` Expected: line count grows by ~3 (one line per 5s) — proves loop + monitor auth + mount
- [ ] **Step 5: Commit**

---

### Task 2: Spike sampler + report script

**Files:**
- Create: `docker/scripts/mysql-recorder.sh`
- Create: `tools/spike-report.sh`

**Interfaces:**
- Consumes: `MONITOR_DIR=logs/monitor`, `MYSQL` helper
- Produces: `logs/monitor/spike-<ts>/{processlist.txt,top-digest.txt,innodb.txt}`, `latest` symlink, `report.html`, plus window mode `spike-report.sh <dir> --since <ts> --until <ts>` for 20h-ago cases

```bash
#!/bin/bash
# docker/scripts/mysql-recorder.sh — 5s cadence, runs as the `recorder` compose service (Task 1)
set -uo pipefail
MC="mysql -h ${MONITOR_HOST:-mysql} -u ${MONITOR_USER:-monitor} -p${MONITOR_PASS} --batch"
RUNNING=$($MC -N -e \
  "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='THREADS_RUNNING'")
echo "$(date -u +%FT%TZ) threads_running=${RUNNING}" >> /logs/monitor/metrics.log
if [ "${RUNNING:-0}" -gt 20 ]; then
  D="/logs/monitor/spike-$(date +%s)"; mkdir -p "$D"
  $MC -e "SHOW FULL PROCESSLIST\G" > "$D/processlist.txt" 2>&1
  $MC "$DB_NAME" -N -e \
    "SELECT DIGEST_TEXT,COUNT_STAR,SUM_TIMER_WAIT FROM performance_schema.events_statements_summary_by_digest ORDER BY SUM_TIMER_WAIT DESC LIMIT 10" > "$D/top-digest.txt" 2>&1
  $MC -e "SHOW ENGINE INNODB STATUS\G" > "$D/innodb.txt" 2>&1
  ln -sfn "$D" /logs/monitor/latest
fi
```

- [ ] **Step 1: Write recorder + report script (state counts, top digest, lock hint, `--since/--until` window + slow-log slice + app-slow slice in one output)**
- [ ] **Step 2: Verify** — Run: `docker compose up -d recorder && sleep 12 && ls logs/monitor/` Expected: `metrics.log` grows (~1 line/5s), `spike-*` only on trigger (force one: set threshold env? no — trigger check comes with Task 2 Step 3 on real load)
- [ ] **Step 3: Verify window mode** — Run: `bash tools/spike-report.sh logs/monitor/latest --since "1 hour ago" --until "now"` Expected: digest + slow-log + app-slow sections print, exit 0 with no spike dir required
- [ ] **Step 4: Commit**

---

### Task 3: PHP slow-only correlation

**Files:**
- Modify: `config.php:40-42`
- Modify: `docker/backup/backup.sh:141`, `send.php:16`

**Mount (wired in Task 1):** app gets `./logs/monitor:/var/www/html/ZZPanelBot/logs/monitor`, backup gets `./logs/monitor:/logs/monitor` — both snippets below therefore append to the same host file `logs/monitor/app-slow.log`. **Source of truth:** this JSON file is what `observe_investigate` section 4 greps; Task 7's `Logger::info('webhook.slow')` is the intentional human-readable duplicate.

```php
// config.php after $sql connects
$__req_start = microtime(true);
register_shutdown_function(function () use ($sql, $__req_start) {
  $ms = (int)((microtime(true) - $__req_start) * 1000);
  if ($ms < 2000) return;
  $threads = $sql->query("SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='THREADS_RUNNING'")->fetch_row()[0] ?? -1;
  file_put_contents(__DIR__.'/logs/monitor/app-slow.log',
    json_encode(['ts'=>gmdate('c'),'ms'=>$ms,'threads'=>$threads,'from'=>$GLOBALS['from_id'] ?? null,'data'=>$GLOBALS['data'] ?? null])."\n", FILE_APPEND);
});
```

```bash
# backup.sh after mysqldump starts
echo "{\"ts\":\"$(date -u +%FT%TZ)\",\"event\":\"backup-start\"}" >> /logs/monitor/app-slow.log
```

- [ ] **Step 1: Add shutdown log (`ms >= 2000` only) + markers**
- [ ] **Step 2: Verify** — Run: `php -l config.php` Expected: no syntax error
- [ ] **Step 3: Commit**

---

### Task 4: Nginx Grafana subpath

**Files:**
- Modify: `docker/nginx/nginx.conf.template`

```nginx
location = /grafana { return 301 $scheme://$http_host/grafana/; }
location /grafana/ {
  proxy_pass http://grafana:3000/;
  proxy_set_header Host $host;
  proxy_set_header X-Real-IP $remote_addr;
  proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
  proxy_set_header X-Forwarded-Proto $scheme;
  # allow <your-ip>; deny all;  # optional privacy
}
```

- [ ] **Step 1: Add location blocks**
- [ ] **Step 2: Verify** — Run: `docker compose --profile observe up -d grafana && sleep 8 && curl -sk -o /dev/null -w "%{http_code}" https://localhost/grafana/login` Expected: `200`
- [ ] **Step 3: Commit**

---

### Task 5: Debug menu (DRY helpers)

**Files:**
- Modify: `setup.sh`

```bash
MONITOR_DIR="logs/monitor"; ARCHIVE_DIR="$MONITOR_DIR/archive"
MYSQL="docker compose exec -T mysql mysql -umonitor -p\"${MONITOR_PASS}\" --batch"
MYSQL_ROOT="docker compose exec -T mysql mysql -uroot -p\"${MYSQL_ROOT_PASSWORD}\" --batch"
mon_ts() { date +%Y%m%d_%H%M%S; }
mon_snapshot_digest() { $MYSQL -N -e \
  "SELECT DIGEST_TEXT,COUNT_STAR,SUM_TIMER_WAIT FROM performance_schema.events_statements_summary_by_digest ORDER BY SUM_TIMER_WAIT DESC LIMIT 20" > "$1"; }
mon_archive() {
  local src="$ARCHIVE_DIR/pre-reset-$1"; mkdir -p "$src"
  mon_snapshot_digest "$src/digest-before.txt"
  cp logs/mysql/slow.log "$src/" 2>/dev/null || true
  mv "$MONITOR_DIR"/spike-* "$src/" 2>/dev/null || true
  tar -czf "$src.tar.gz" -C "$ARCHIVE_DIR" "pre-reset-$1" && rm -rf "$src"
}
mon_reset_counters() {
  $MYSQL_ROOT -e "TRUNCATE TABLE performance_schema.events_statements_summary_by_digest;"
  : > logs/mysql/slow.log; : > "$MONITOR_DIR/metrics.log"; : > "$MONITOR_DIR/app-slow.log";
}
observe_status() { $MYSQL -e \
  "SELECT VARIABLE_NAME,VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME IN ('THREADS_RUNNING','SLOW_QUERIES')"; ls -t "$MONITOR_DIR"/spike-*/ 2>/dev/null | head -3; }
observe_report() { bash tools/spike-report.sh "${1:-$MONITOR_DIR/latest}"; }
observe_reset() { local ts; ts=$(mon_ts); mon_archive "$ts" || return 1; mon_reset_counters;
  INFO "Archived to $ARCHIVE_DIR/pre-reset-$ts.tar.gz, counters reset."; }
# One-command investigation: spike dir OR time window, prints verdict
mon_pick_spike() { # $1 = latest | spike-dir path | (empty = newest spike dir)
  if [ -n "${1:-}" ] && [ -d "$1" ]; then echo "$1"; else echo "$MONITOR_DIR/latest"; fi; }
observe_investigate() { # usage: observe_investigate [spike-dir] [--since TS --until TS]
  require_installed || return 1
  local target; target=$(mon_pick_spike "${1:-}")
  bash tools/spike-report.sh "$target" "${@:2}"
  INFO "Grafana: https://$DOMAIN/grafana/ (set window to spike ts above)";
}
```

Menu `15) Observability & Debug` → `1 status / 2 report / 3 UI start / 4 UI stop / 5 reset / 6 investigate (one-command: spike + digest + slow-log + app handler + verdict)`.

- [ ] **Step 1: Add helpers + submenu wiring (incl. `observe_investigate` + `mon_pick_spike`)**
- [ ] **Step 2: Verify** — Run: `bash -n setup.sh && bash tools/spike-report.sh --help | grep -c "since"` Expected: no syntax error, help mentions window flags
- [ ] **Step 3: Commit**

---

### Task 6: Sentry app truth (5% sampled, SaaS free)

**Gate:** 1 org, 5 projects (one per customer), sole debugger. Shared quota 5k errors + 5M spans/mo, 30-day retention. No local collector (zero host CPU).

**Files:**
- Modify: `config.php` (Sentry init + transaction per webhook, after `$sql` connect)
- Modify: `App/apis/marzban-api/api.php` (`getUsersInfo`, `loginPanel` span wraps)
- Create: `App/Utils/handlers/SentryHandler.php` (Logger→Sentry bridge: INFO/WARNING → breadcrumb, ERROR/CRITICAL → event)
- Create: `composer.json` + `vendor/` — first composer usage; `composer require sentry/sentry:^3.7` (PHP 7.4 line; `sentry/sdk` meta package NOT used)
- Modify: `Dockerfile` (runtime vendor layer — SDK ships in image, see snippet below)
- Modify: `.env.example` (add `SENTRY_DSN`, `SENTRY_ENVIRONMENT`, sample rates)

```php
// config.php — init guarded: SDK class absent or DSN empty = disabled, app keeps running
if (class_exists('\\Sentry\\Options') && getenv('SENTRY_DSN')) {
  \Sentry\init(['dsn' => getenv('SENTRY_DSN'),
    'environment' => getenv('SENTRY_ENVIRONMENT') ?: 'prod',
    'traces_sample_rate' => 0.05, 'profiles_sample_rate' => 0.0]);
  $GLOBALS['__sentry_tx'] = \Sentry\startTransaction(['name' => $GLOBALS['data'] ?? 'webhook']);
}
```

```php
// api.php inside getUsersInfo() — PHP 7.4: no nullsafe operator (?-> is 8.0+)
$tx = $GLOBALS['__sentry_tx'] ?? null;
$span = $tx ? $tx->startChild(['op' => 'panel.users']) : null;
$response = curl_exec($ch);
if ($span) $span->finish();
```

```dockerfile
# Dockerfile — runtime vendor layer (Sentry SDK ships with the image; no composer at runtime)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-autoloader --classmap-authoritative
```

```php
// App/Utils/handlers/SentryHandler.php — Logger→Sentry bridge (one-way)
class SentryHandler implements LogHandler {
  public function handle(array $record): void {
    if (in_array($record['level'], ['INFO', 'WARNING'], true)) {
      \Sentry\addBreadcrumb(new \Sentry\Breadcrumb(
        \Sentry\Breadcrumb::LEVEL_INFO, \Sentry\Breadcrumb::TYPE_DEFAULT,
        $record['file'] . ':' . $record['line'], $record['msg'], $record['context']));
      return;
    }
    \Sentry\withScope(function ($scope) use ($record) {
      $scope->setExtra('context', $record['context']);
      $scope->setTag('handler', $GLOBALS['data'] ?? 'webhook');
      if (isset($GLOBALS['from_id'])) $scope->setUser(['id' => (string)$GLOBALS['from_id']]);
      \Sentry\captureMessage($record['msg'], $record['level'] === 'CRITICAL'
        ? \Sentry\Severity::fatal() : \Sentry\Severity::error());
    });
  }
}
// config.php — after Sentry init: Logger::addHandler(new SentryHandler());
// file logs carry 'sentry_trace' => sentry_trace_id() for local→Sentry jump
function sentry_trace_id(): ?string {
  if (function_exists('\\Sentry\\getTraceId')) return \\Sentry\\getTraceId();
  return null; // Step 2 verifies whether sentry-php exports it; null = omit field, no crash
}
```

```bash
# .env per customer
SENTRY_DSN=https://xxx@o123.ingest.sentry.io/1
SENTRY_ENVIRONMENT=customer-A
# SENTRY_TRACES_SAMPLE_RATE=0.05 (1 in 20 traced; errors always sent)
# SENTRY_PROFILES_SAMPLE_RATE=0.0
# Empty SENTRY_DSN = Sentry disabled, file logs still work
```

- [ ] **Step 1: `composer require sentry/sentry:^3.7` + init (guarded) + 2 span wraps (7.4-safe) + `SentryHandler` bridge + env docs + Dockerfile vendor layer**
- [ ] **Step 2: Verify** — Run: `SENTRY_DSN=<test> php -r "require 'vendor/autoload.php'; \Sentry\init(['dsn'=>getenv('SENTRY_DSN'),'traces_sample_rate'=>1.0]); echo 'ok';"` Expected: `ok` + 1 test transaction in Sentry project; empty DSN run Expected: no error, file logs only
- [ ] **Step 3: Commit**

**Menu hook:** `observe_investigate` prints `Sentry: search environment:<customer> 02:25-02:40, sort by duration` alongside the Grafana link. Recorder proves MySQL guilty/innocent; Sentry proves which span. Unit tests for the bridge land in Task 8 (`SentryHandlerTest` via `SentryGateway` seam — Task 8 wraps the direct `\Sentry\*` calls with the gateway, no behavior change).

---

### Task 7: Log points (INFO/WARNING/ERROR, prod level INFO, no ms change)

**Gate:** Logger stays second-precision (`date('c')`, `Y-m-d H:i:s`). No `DEBUG` in prod. Sanitized contexts only (never tokens, passwords, full bind values).

**Consolidated edit surface:** Tasks 3, 6, 7 all insert at the same config.php anchor (`after $sql connects`). Apply sequentially in task order; by end of Task 7 the block must be exactly this shape (single snippet, reviewer checks against it — no task leaves a partial merge behind):

```php
require_once __DIR__ . '/vendor/autoload.php';           // Task 6
Logger::init(...);                                        // existing
if (class_exists('\\Sentry\\Options') && getenv('SENTRY_DSN')) {   // Task 6
    \Sentry\init([...]); $GLOBALS['__sentry_tx'] = \Sentry\startTransaction(...);
    Logger::addHandler(new SentryHandler(new SentryGateway()));
}
$__req_start = microtime(true);                           // Task 3
register_shutdown_function(function () use ($sql, $__req_start) {  // Tasks 3+7 merged body
    $ms = (int)((microtime(true) - $__req_start) * 1000);
    if ($ms < 2000) return;
    $threads = $sql->query(...THREADS_RUNNING...) ...
    file_put_contents(__DIR__.'/logs/monitor/app-slow.log', json_encode([...
      'sentry_trace' => sentry_trace_id(),]))...
    Logger::info('webhook.slow', [...]);                  // Task 7
});
```

**Files:**
- Modify: `config.php` (webhook-slow INFO)
- Modify: `App/apis/marzban-api/api.php` (panel slow/failed, token refresh)
- Modify: `App/functions/service_overview.php` (per-panel INFO, archive ERROR)
- Modify: `workers/task_worker.php` (stale WARNING, task failed ERROR)
- Modify: `send.php` (broadcast start/done)
- Modify: `App/apis/telegram-api/api/*.php` (429 `retry_after` in context)

```php
// config.php — webhook slow only
$ms = (int)((microtime(true) - $__req_start) * 1000);
if ($ms >= 2000) Logger::info('webhook.slow', ['handler' => $GLOBALS['data'] ?? null, 'ms' => $ms, 'threads' => $threads]);

// api.php — panel slow/failed + token refresh
Logger::info('panel.users.slow', ['panel' => $name, 'ms' => $ms, 'n' => count($names)]);       // ms > 5000
Logger::warning('panel.users.failed', ['panel' => $name, 'ms' => $ms, 'error' => $err]);
Logger::warning('panel.token.refresh', ['panel' => $name]);
Logger::error('panel.token.dead', ['panel' => $name]);
// service_overview.php
Logger::info('overview.panel', ['panel' => $loc, 'ms' => $ms, 'active' => $a, 'unknown' => $u]);
Logger::error('overview.archive.failed', ['order_id' => $id, 'error' => $sql->error]);
// task_worker.php
Logger::warning('worker.stale.recovered', ['count' => $n]);
Logger::error('worker.task.failed', ['task_id' => $id, 'type' => $t, 'error' => $e->getMessage()]);
// send.php
Logger::info('broadcast.start', ['by' => $from_id, 'total' => $n]);
Logger::info('broadcast.done', ['sent' => $n, 'ms' => $ms]);
```

- [ ] **Step 1: Add call sites per list above, `LOG_LEVEL=INFO` in prod**
- [ ] **Step 2: Verify** — Run: `php -l config.php && php -l workers/task_worker.php && grep -rn "overview.panel\|worker.task.failed\|broadcast.start" App workers send.php | wc -l` Expected: no syntax errors, count >= 5
- [ ] **Step 3: Commit**

**Menu hook:** `observe_investigate` greps `overview.panel.slow|panel.users.failed|broadcast.start` in the spike window as section 4. Routing/level tests for these call sites are backfilled in Task 8 (`LoggerRoutingTest`).

---

### Task 8: Test scaffolding + selfcheck migration (PHPUnit ^9.6, CI-only, single runner)

**Gate:** Image changes limited to the Task 6 vendored Sentry layer — only `tests/`, `phpunit.xml` stay out of the image (`.dockerignore`); `vendor/` + `composer.lock` ship (runtime SDK). PHPUnit pinned `^9.6` (10.x needs PHP 8.1; stack is 7.4). Single test system: every check in `tools/logger_selfcheck.php` migrates into PHPUnit suites and the file is deleted — never two runners. Backfills unit tests for Tasks 5-7; green CI is the gate for Task 9.

**Files:**
- Create: `composer.json`, `phpunit.xml`, `tests/bootstrap.php`
- Create: `tests/Fakes/{FakeResult,FakeStmt,FakeSql}.php` + `tests/Fakes/{FakeGateway,CollectingHandler}.php` (extracted verbatim from selfcheck lines 20-60)
- Create: `tests/arch/SourceAsserts.php` (source-regression suite, data-provider driven)
- Create: `tests/unit/{LoggerConfigTest,LoggerConfigEnvTest,LoggerTest,FileHandlerTest,TelegramHandlerTest,SenderTokenTest,RequestCacheTest,PanelCacheTest,ApcuHelpersTest,DbToolsTest,CacheKeysTest,SpikeVerdictTest,LoggerRoutingTest,SentryHandlerTest,CliSentrySafeTest}.php` + `tests/fixtures/spike-sample/summary.json`
- Create: `App/Utils/SpikeVerdict.php` (pure rules; `tools/spike-report.sh` calls it, becomes thin wrapper)
- Create: `App/Utils/SentryGateway.php`; Modify `App/Utils/handlers/SentryHandler.php` (route via gateway — no behavior change)
- Delete: `tools/logger_selfcheck.php` (only after ALL 22 checks ported and suites green)
- Modify: `.github/workflows/ci.yml` (add `test` job), `.dockerignore` (add `tests/`, `phpunit.xml` ONLY — vendor must ship for Sentry), docs/AGENTS run instructions (`composer test` replaces `php tools/logger_selfcheck.php`)

**Interfaces:**
- Consumes: summary fixture `states[] / digest{sql,exam,sent} / app_ms[] / markers[]` shape that spike-report writes
- Produces: `composer test` one command; green CI = precondition for Task 9

```json
{
  "description": "ZZPanelBot",
  "require": { "php": ">=7.4", "sentry/sentry": "^3.7" },
  "require-dev": { "phpunit/phpunit": "^9.6" },
  "autoload-dev": { "psr-4": { "Tests\\": "tests/unit/" } },
  "scripts": {
    "test": [
      "vendor/bin/phpunit",
      "bash -n setup.sh",
      "shellcheck --severity=error docker/scripts/mysql-recorder.sh tools/spike-report.sh",
      "docker compose -f docker-compose.yml config -q",
      "docker compose -f docker-compose.build.yml config -q"
    ]
  }
}
```

```xml
<!-- phpunit.xml — two suites: unit (behavior) + arch (source invariants) -->
<phpunit bootstrap="tests/bootstrap.php" colors="true" failOnRisky="true">
  <testsuites>
    <testsuite name="unit"><directory>tests/unit</directory></testsuite>
    <testsuite name="arch"><directory>tests/arch</directory></testsuite>
  </testsuites>
  <php>
    <env name="SENTRY_DSN" value=""/>
    <env name="LOG_TELEGRAM_DEDUP_ENABLED" value=""/>
  </php>
</phpunit>
```

```php
// App/Utils/SpikeVerdict.php — pure: array in, string out. No I/O, no DB, no clock.
final class SpikeVerdict {
  public static function make(array $in): string {
    $rules = [];
    $exam = (int)($in['digest']['exam'] ?? 0); $sent = (int)($in['digest']['sent'] ?? 0);
    if ($sent > 0 && $exam > $sent * 100)
      $rules[] = 'missing index: ' . (string)($in['digest']['sql'] ?? '?');
    if (($in['states']['Waiting for table metadata lock'] ?? 0) > 0)
      $rules[] = 'DDL lock (service_overview.php orders_archive)';
    $max = $in['app_ms'] ? max($in['app_ms']) : 0;
    if ($max >= 55000) $rules[] = 'panel stall (~60s getUsersInfo timeout)';
    if (($in['states']['Sleep'] ?? 0) > 5 && $max === 0)
      $rules[] = 'PHP holding connections while waiting on panel';
    if (!$rules) return 'verdict: no dominant cause recorded';
    return "verdict:\n- " . implode("\n- ", $rules);
  }
}
```

```php
// tests/unit/SpikeVerdictTest.php — data providers cover every verdict rule
use PHPUnit\Framework\TestCase;
final class SpikeVerdictTest extends TestCase {
  /** @dataProvider causesProvider */
  public function test_rules_fire(string $expected, array $input): void {
    $this->assertStringContainsString($expected, SpikeVerdict::make($input));
  }
  public static function causesProvider(): array {
    $base = ['states' => [], 'digest' => ['sql' => 'SELECT * FROM `orders` WHERE `from_id` = ?', 'exam' => 1, 'sent' => 1], 'app_ms' => []];
    $m = fn(array $o) => array_merge($base, $o);
    return [
      'missing index'        => ['missing index',        $m(['digest' => ['sql' => 'orders', 'exam' => 820000, 'sent' => 412]])],
      'no scan at 1:1'       => ['no dominant',          $base],
      'ddl lock'             => ['DDL lock',             $m(['states' => ['Waiting for table metadata lock' => 4]])],
      'panel stall at 60s'   => ['panel stall',          $m(['app_ms' => [59800]])],
      'sleep pile w/o panel' => ['holding connections',  $m(['states' => ['Sleep' => 9]])],
      '30s not treated stall' => ['no dominant',         $m(['app_ms' => [30000]])],
    ];
  }
}
```

**Selfcheck → suite mapping — ALL 22 checks migrated (assert() → assertSame/ContainsString):**

| selfcheck check | new test(s) | port note |
|---|---|---|
| check_config_defaults | `LoggerConfigTest` | asserts as-is (level, buffer, NullHandler seen) |
| check_file_handler | `FileHandlerTest` | dual format + rotation + missing-dir non-fatal; temp dir unique per test, deleted in tearDown |
| check_logger_core | `LoggerTest` | level pivot, trace only on warn+, buffer drop-oldest |
| check_telegram_handler | `TelegramHandlerTest` | min-level filter, dedup window, 1024 caption cap, absent `sendFile()` no-op |
| check_dedup_toggle | folded into `TelegramHandlerTest` | disabled/enabled + testmenu `test:<id>` key + env parse fallback |
| check_sender_token | `SenderTokenTest` | `sendFile` stub moves from eval to `tests/bootstrap.php` |
| check_membership_memo | `MembershipMemoTest` — written properly | old check asserted NOTHING (stub only). New: with bootstrap `bot()` stub, two membership checks within TTL → `$GLOBALS['__bot_calls']` stays 1. If memo path is not invokable outside `index.php` globals, degrade cleanly to an arch needle on the memo call site — note in review |
| check_request_cache | `RequestCacheTest` | closure factory runs once |
| check_panel_cache_hit | `PanelCacheTest` | shared FakeSql + `__query_count` assertions identical |
| check_apcu_helpers | `ApcuHelpersTest` | both branches ported as two cases: apcu-present and apcu-absent (`function_exists('apcu_store')` split stays) |
| check_db_row | `DbToolsTest` | row-or-null + exec |
| check_cache_keys | `CacheKeysTest` (behavior: `panel_key`/`temp_key`/temp-file round-trip w/ unique names) + arch rules (TTL constants, `apcu_warn_once`) | mixed check split |
| check_env_overrides | `LoggerConfigEnvTest` | putenv in setUp, cleared in tearDown (no value = unset) — no process isolation needed |
| check_env_invalid | `LoggerConfigEnvTest` | warnings count asserted |
| check_wiring | arch rule `wiring` | |
| check_call_sites | arch rule `call_sites` | |
| check_skip_locked | arch rule `skip_locked` | |
| check_ddl_hoist | arch rule `ddl_hoist` | `substr_count == 1` |
| check_batch_flags | arch rules `batch_flags`, `sleep_gone` | negative needle `!sleep(0.5)` |
| check_telegram_api_single | arch rules `tg_api_split`, `tg_helper_logging`, `tg_helpers_call` | |
| check_testmenu_gate | arch rule `testmenu_gate` | gate occurrence count `>= 4` |
| (new coverage) | `SpikeVerdictTest`, `LoggerRoutingTest`, `SentryHandlerTest`, `CliSentrySafeTest` | this task's own additions, unchanged |

**Arch suite — one helper class + data provider (no 7 near-identical files, reads repo files hermetically):**

```php
// tests/arch/SourceAsserts.php — reads repo files only; never writes, never runs app code
use PHPUnit\Framework\TestCase;
final class SourceAsserts extends TestCase {
  private static function src(string $rel): string {
    return file_get_contents(__DIR__ . '/../../' . $rel);
  }
  /** @dataProvider rules */
  public function test_invariant(string $label, string $rel, array $must, array $mustNot = [], array $once = []): void {
    $s = self::src($rel);
    foreach ($must as $n) $this->assertStringContainsString($n, $s, $label);
    foreach ($mustNot as $n) $this->assertStringNotContainsString($n, $s, $label);
    foreach ($once as $n) $this->assertSame(1, substr_count($s, $n), $label);
  }
  public static function rules(): array {
    return [
      'wiring'         => ['wiring', 'config.php', ['Logger::init'], [], []],
      'call_sites'     => ['call sites', 'index.php', ['Logger::error'], [], []],
      'call_sites_2'   => ['call sites', 'App/apis/marzban-api/api.php', ['Logger::error'], [], []],
      'call_sites_3'   => ['call sites', 'App/functions/service_overview.php', ['Logger::info'], [], []],
      'skip_locked'    => ['skip locked', 'workers/task_worker.php', ['SKIP LOCKED'], [], []],
      'ddl_hoist'      => ['ddl hoist', 'App/functions/service_overview.php', ['CREATE TABLE IF NOT EXISTS'], [], ['CREATE TABLE IF NOT EXISTS']],
      'batch_flags'    => ['batch flags', 'App/Utils/handlers/FileHandler.php', ['$pruned'], [], []],
      'sleep_gone'     => ['sleep gone', 'custom.php', [], ['sleep(0.5)'], []],
      'testmenu_gate'  => ['testmenu gate', 'index.php', ['\'/testmenu\''], [], []],
      'env_ttl'        => ['ttl constants', 'config.php', ["define('CONFIG_TTL', 300)", "define('SCHEMA_TTL', 60)", "define('MEMBER_TTL', 60)", 'function membership_key'], [], []],
      'warn_once'      => ['warn once', 'App/Utils/apcu_tools.php', ['apcu_warn_once'], [], []],
      'worker_log'     => ['worker log', 'workers/task_worker.php', ['Logger::info'], [], []],
      'tg_api_split'   => ['tg api single source', 'App/apis/telegram-api', ['utils/api_utils.php'], ['api.php'], []], // file-absence rules use is_file asserts in a dedicated test
      'resp_log'       => ['resp log', 'App/apis/telegram-api/functions/responses.php', ['Logger::warning', 'Logger::error'], [], []],
    ];
  }
}
```

(plus one dedicated `TelegramApiCompactTest` for `is_file` absence/agent file checks and the per-helper `Logger::warning|Logger::info` needle loop — file-existence assertions don't fit the string helper.)

**Bootstrap defines the eval-stub globals once (never eval in tests):**

```php
// tests/bootstrap.php — global fakes the selfcheck used to eval-inline
if (!function_exists('sendFile')) {
  function sendFile($chat_id, $file_path, $file_name, $mime_type, $api_key = null, $caption = null) {
    $GLOBALS['__tg_cap'] = func_get_args(); return array('ok' => true);
  }
}
if (!function_exists('bot')) {
  function bot($m, $d = array()) { $GLOBALS['__bot_calls'] = ($GLOBALS['__bot_calls'] ?? 0) + 1; return array('result' => array('status' => 'member')); }
}
```

**Porting rules for the new suites:**
- `putenv` tests: each case sets its own env in setUp and clears every key in tearDown (`putenv('KEY')` = unset) — hermetic without process isolation.
- Temp files: unique names (`getmypid()` suffix like today), deleted in tearDown — tests must be re-runnable; today's script leaks files in `sys_get_temp_dir()` forever.
- Fail-fast parity: selfcheck exited on first failure; PHPUnit runs all and reports everything (strictly better) — do not add `--stop-on-failure`.

New suites added by this task (unchanged from agreement):
- `SpikeVerdictTest` — data providers cover every verdict rule (see class listing above).
- `LoggerRoutingTest` — real `Logger` + `CollectingHandler`: DEBUG dropped at `INFO`, INFO/WARNING/ERROR kept; buffer flush boundaries.
- `SentryHandlerTest` — `FakeGateway`: INFO/WARNING → `breadcrumbs`, ERROR/CRITICAL → `captured` with fatal severity for CRITICAL, `null` gateway = no-op (empty `SENTRY_DSN` path).
- `CliSentrySafeTest` — two layers: unit part requires ONLY `App/Utils/handlers/SentryHandler.php` with a fake gateway and no Sentry classes loaded, asserts no fatal; the full `config.php` CLI include moves to the CI integration job (it needs DB envs — a bare CLI include hits the mysqli error path and would fail spuriously).

```yaml
# .github/workflows/ci.yml — new job after lint
  test:
    name: Unit + Selfcheck + Integration
    needs: lint
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env: { MYSQL_ROOT_PASSWORD: test, MYSQL_DATABASE: botpanel }
        ports: ["3306:3306"]
        options: >-
          --health-cmd="mysqladmin ping -h localhost"
          --health-interval=5s --health-timeout=3s --health-retries=10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '7.4', coverage: none, tools: composer:v2 }
      - run: composer install --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/phpunit
      - run: |
          shellcheck --severity=error docker/scripts/mysql-recorder.sh tools/spike-report.sh setup.sh
          docker compose -f docker-compose.yml config -q
          docker compose -f docker-compose.build.yml config -q
      - name: Integration — schema + idempotent migration + EXPLAIN (Task 9 gate)
        run: |
          MYSQL_HOST=127.0.0.1 MYSQL_ROOT_PASSWORD=test MYSQL_DATABASE=botpanel bash tests/integration/run-migration.sh
          MYSQL_HOST=127.0.0.1 MYSQL_ROOT_PASSWORD=test MYSQL_DATABASE=botpanel bash tests/integration/run-migration.sh
          MYSQL_HOST=127.0.0.1 MYSQL_ROOT_PASSWORD=test MYSQL_DATABASE=botpanel bash tests/integration/explain-hot-queries.sh
```

- [ ] **Step 1: Scaffold composer/phpunit/bootstrap; extract shared Fakes; move eval-stub globals (`sendFile`, `bot`) into bootstrap**
- [ ] **Step 2: Extract SpikeVerdict; spike-report.sh calls it (same output text, byte-identical on fixture)**
- [ ] **Step 3: Port behavioral checks → tests/unit (LoggerConfig, Logger, FileHandler, TelegramHandler incl. dedup toggle, SenderToken, MembershipMemo, RequestCache, PanelCache, ApcuHelpers, DbTools, CacheKeys, EnvConfig) — both runners green after this step**
- [ ] **Step 4: Port source checks → tests/arch via SourceAsserts data provider (wiring, call sites, skip locked, ddl hoist, batch flags, sleep gone, ttl env, telegram api single, testmenu gate) — both runners green**
- [ ] **Step 5: SentryGateway seam + new suites (SpikeVerdictTest, LoggerRoutingTest, SentryHandlerTest, CliSentrySafeTest) + fixture `tests/fixtures/spike-sample/summary.json` committed**
- [ ] **Step 6: Delete `tools/logger_selfcheck.php`; CI `test` job gets phpunit only; `.dockerignore` + docs/AGENTS run-instruction updates; integration scripts for migration idempotency + EXPLAIN**
- [ ] **Step 7: Verify** — Run: `composer test` Expected: phpunit green (unit + arch suites), no syntax errors, both compose files valid, exit 0 — selfcheck no longer exists
- [ ] **Step 8: Verify hermetic** — Run: `vendor/bin/phpunit` twice in a row with no docker/network up Expected: pass both times (tests write only under unique temp dirs and clean up in tearDown)
- [ ] **Step 9: Commit**

---

### Task 9: Index fixes (AFTER Tasks 1-8 done, tested, reviewed)

**Gate:** Do not start until Tasks 1-8 are merged + `composer test` green in CI + `observe_investigate` verified on a real spike folder. Indexes use recorder proof (`exam/sent`, `EXPLAIN type=ALL`) as the change justification.

**Files:**
- Modify: `sql/sql.php` (guarded adds: `SHOW INDEX` first, then conditional `ALTER TABLE ... ADD INDEX` — same pattern as sql.php:248-252; MySQL 8.0 has no `CREATE INDEX IF NOT EXISTS`)
- Create: `sql/migrations/2026-10-16-add-hot-indexes.sql` (idempotent, for existing prod)

```sql
-- hot-path indexes proven by digest (Task 2 output)
-- idempotent at runtime: wrap each in SHOW INDEX check (sql.php pattern) or apply once via migration runner
ALTER TABLE `users` ADD INDEX `idx_users_from_id` (`from_id`);
ALTER TABLE `orders` ADD INDEX `idx_orders_from_id` (`from_id`);
ALTER TABLE `orders` ADD INDEX `idx_orders_from_code` (`code`);
ALTER TABLE `service_factors` ADD INDEX `idx_service_factors_from_id` (`from_id`);
ALTER TABLE `panels` ADD INDEX `idx_panels_name` (`name`);
-- task_queue omitted on purpose: sql.php:249-252 already adds idx_status_id (status, id);
-- a second status-prefixed index doubles hot-path write cost. If the recorder proves
-- (status, locked_at) needed AFTER merge, it REPLACES idx_status_id in one migration — never both.
```

- [ ] **Step 1: Write migration + `sql.php` guards, gated on recorder evidence**
- [ ] **Step 2: Verify** — Run: `EXPLAIN SELECT * FROM orders WHERE from_id='X'` Expected: `type=ref key=idx_orders_from_id` (was `ALL/NULL`); `observe_reset` → reload → digest `exam/sent` near 1:1
- [ ] **Step 3: Commit**

---

## Per-Query Reference

Digest (pattern): `SELECT ... FROM performance_schema.events_statements_summary_by_digest ORDER BY SUM_TIMER_WAIT DESC` — `exam >> sent` proves missing index.
Slow log (sample): `pt-query-digest logs/mysql/slow.log` — full query + `EXPLAIN type=ALL key=NULL`.
Live (blocker): `SHOW FULL PROCESSLIST` — `Sending data` = scan, `metadata lock` = DDL, `Sleep` pile = panel-blocked PHP.

## Incident Walkthrough

21:14 hang → 21:35 `zzbot 15 → 1` shows threads 48, `2` shows `orders WHERE from_id` 96s total 2000:1 exam/sent + metadata lock + Sleep pile → Grafana confirms no backup marker → fix index, `5 reset` for clean baseline.

## One-Command Investigation (5 min slow, 20h ago, no exception)

Menu `15 → 6 investigate` runs everything and prints a verdict — no manual grep chain:

```
zzbot 15 → 6
# or: observe_investigate logs/monitor/spike-20261015_023100
# or: observe_investigate latest --since "2026-10-15 02:25" --until "2026-10-15 02:40"
```

Single output, in order:

```
=== 1. SPIKE WINDOW ===
dir: spike-20261015_023100 | trigger threads_running=48 at 02:31:00 | duration ~300s
states: 31x Sending data, 9x Sleep, 4x Waiting for table metadata lock

=== 2. TOP QUERY (digest) ===
SELECT * FROM `orders` WHERE `from_id` = ? | 412 calls 96s total 230ms avg | exam 820k sent 412 (2000:1 → missing index)

=== 3. SLOW-LOG SAMPLE + PLAN ===
SELECT * FROM `orders` WHERE `from_id` = '842131' AND `code` = 'vip1';
EXPLAIN: type=ALL key=NULL rows=18400 → ADD INDEX(from_id)

=== 4. APP HANDLER (who triggered it) ===
02:31:03 ms=62000 threads=41 data=^srv_lst@loc_lst@my_services_menu
02:31:09 ms=58000 threads=47 data=^stat_lst
→ service-list path, ~60s = Marzban getUsersInfo() timeout (api.php:320)

=== 5. BLOCKER ===
Sending data pile = slow scans; metadata lock = DDL service_overview.php:126; Sleep pile = panel-blocked PHP

=== VERDICT ===
service: MySQL saturated by orders scans | part: service-list + slow panel + missing index
fix queue: 1) ADD INDEX(from_id) 2) DDL out of request 3) cap panel timeout
graphs: https://DOMAIN/grafana/ @ 02:25-02:40
```

Error log stays empty because nothing threw — timing + counters carry the diagnosis, and the menu bundles all five sections into one call.
