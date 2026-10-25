# MySQL Hot-Path Indexes and Server Tuning — Spec

**Date:** 2026-10-22
**Status:** Implemented and verified
**Related plan:** [`docs/superpowers/plans/2026-10-15-mysql-spike-flight-recorder.md`](superpowers/plans/2026-10-15-mysql-spike-flight-recorder.md) (Tasks 1 and 9 only — the rest of that plan is deferred, see [Deferred](#deferred-not-in-this-round))

**Goal:** Give the bot's hot database paths an index instead of a full table scan, and turn on the MySQL-side diagnostics needed to explain the next multi-minute hang.

---

## Context

`bt hang 2min` — the Telegram bot stops answering for roughly 120 seconds, matching `max_execution_time=120` (`docker/php/botpanel-perf.ini:14`) and `set_time_limit(120)` (`index.php:36-47`).

The flight-recorder plan (linked above) proposes nine tasks: a slow-log/digest recorder, Prometheus, Grafana, Sentry, a PHPUnit migration, and finally index fixes gated on the recorder's measured evidence. The index task (Task 9) is gated on the recorder being built and running, which is eight tasks of monitoring work away.

**Decision taken:** ship the index and server-tuning work now, from what the code proves, and let the recorder land afterwards to confirm the diagnosis and catch what static reading misses. The recorder is not blocked by the indexes; the indexes are not blocked by the recorder.

## What the code actually shows

The plan's ranked hypothesis 1 ("missing secondary indexes") was checked against every `orders` query in the repository. `sql/sql.php` created primary keys only — the sole secondary index in the schema was `idx_status_id` on `task_queue` (`sql/sql.php:248-252`).

Query pattern census for `orders` (35 sites across `index.php`, `custom.php`, `App/functions/service_overview.php`):

| Pattern | Sites | Notes |
|---|---|---|
| `WHERE code = ? AND from_id = ?` | 18 | the dominant shape |
| `WHERE from_id = ?` | 10 | covered by the leftmost prefix of a composite index |
| `WHERE from_id = ? AND location = ? ORDER BY row DESC` | 1 | `index.php:796` |
| `WHERE code = ?` alone | 1 | `custom.php:446` — the UPDATE, see below |
| `WHERE row = ?` | 3 | primary key, already indexed |

**`orders`.`code` holds the base plan name, not a per-user code.** `index.php:440` inserts `code = $code_base`; the per-user name (`$code_base . '_' . $from_id`) is only used for Marzban panel API calls (`index.php:1206`). So `code` has almost no selectivity on its own — a standalone index on `orders`.`code` would be nearly useless, and the plan's proposed pair of separate single-column indexes would force an index merge for the 18-site dominant pattern.

**Scale.** Production holds fewer than 10k `orders` rows and fewer than 1k `users`. A full scan of that size is single-digit milliseconds, so the missing indexes are a real but modest cost — **they are not the explanation for a 120-second hang.** The dominant suspect remains the 60-second Marzban panel timeout in `App/apis/marzban-api/api.php` (`CURLOPT_TIMEOUT` 30, connect 30) compounded by the N+1 panel calls in the service-list path. The indexes are correct, cheap, and worth shipping; they are not the fix for the hang itself, and the recorder still has to prove where the time actually goes.

## Changes

### 1. Hot-path indexes

Delivered in `sql/sql.php` as a guarded migration — `SHOW INDEX` first, then a conditional `ALTER TABLE`, the same pattern already used for `idx_status_id`. MySQL 8.0 has no `CREATE INDEX IF NOT EXISTS`.

`sql/sql.php` runs on every install, on every app container start (`docker-entrypoint.sh:56`), and on every `update.sh`, so existing deployments pick the indexes up on the next deploy without an operator step.

| Table | Index | Columns | Serves |
|---|---|---|---|
| `orders` | `idx_orders_from_id_code` | `(from_id, code)` | the 18-site `code`+`from_id` pattern and the 10-site `from_id`-only pattern |
| `users` | `idx_users_from_id` | `(from_id)` | the per-webhook user lookup (`config.php:92`) and `UPDATE users SET coin = coin - ?` (`custom.php:447`) |
| `service_factors` | `idx_service_factors_from_id` | `(from_id)` | `SELECT`/`DELETE ... WHERE from_id = ?` on every purchase and renewal |
| `factors` | `idx_factors_code` | `(code)` | payment-callback lookup and update, on a table that grows forever |

A composite `(from_id, code)` rather than two single-column indexes, because the leftmost prefix already answers every `WHERE from_id = ?` lookup, and the composite answers the dominant `code`+`from_id` pattern in one seek.

`sql/migrations/2026-10-20-hot-indexes.sql` applies the same four indexes to a live database without a redeploy. It is idempotent — each `ALTER` is guarded through `information_schema.statistics`, since MySQL 8.0 has no conditional DDL.

### 2. `custom.php` cross-user data corruption

`custom.php:446` ran:

```sql
UPDATE `orders` SET `location = ..., `date` = ..., `volume` = ..., `link` = ..., `price` = ...
WHERE `code` = '$code_base'
```

`$code_base` is the base plan name, shared by every customer who bought that plan. The statement therefore overwrote the location, expiry, volume, **config link** and price of every other customer's order for the same plan. Fixed by adding `AND from_id = '$from_id'`, which also makes the statement use `idx_orders_from_id_code` instead of scanning.

### 3. DDL out of the request path

`App/functions/service_overview.php` ran `CREATE TABLE IF NOT EXISTS orders_archive LIKE orders` on every service-overview request. Even when the table already exists this takes a metadata lock — one of the four candidate causes of the hang. The DDL moved to `sql/sql.php`, which runs at install and update.

`orders_archive` is created *before* the hot indexes are added, on purpose: `LIKE` copies the table definition, so the archive keeps its primary key only and does not carry indexes it never queries (it is only ever read by `row`).

### 4. MySQL server configuration

`docker/mysql/lowcpu.cnf`, mounted read-only at `/etc/mysql/conf.d/lowcpu.cnf` by both compose files. This file is the only place these values are set — never duplicated through the compose `command:` option, which is how tuning drifts.

| Setting | Value | Why |
|---|---|---|
| `slow_query_log` | `1` | the next hang must be explainable after the fact |
| `slow_query_log_file` | `/var/log/mysql/slow.log` | on the host, via the `./logs/mysql` mount |
| `long_query_time` | `2` | matches the 2-second webhook threshold used elsewhere |
| `log_queries_not_using_indexes` | `0` | every index-less lookup would be logged; floods the slow log on 1 vCPU |
| `performance_schema` | `ON` | the recorder's data source |
| `performance-schema-consumer-events-statements-history` | `OFF` | the recorder reads the by-digest summary, not the per-statement history |
| `performance-schema-consumer-events-waits-history` | `OFF` | same, and already the MySQL 8 default |
| `innodb-buffer-pool-size` | `128M` | sized for a 2 GB host, not to hold the data (the whole dataset is far smaller) |
| `max-connections` | `60` | must match Apache's `MaxRequestWorkers`, see below |

The `mysql` image's entrypoint reads `slow-query-log-file` from the config, creates the parent directory and chowns it to `mysql`, so the `./logs/mysql` bind mount is writable without extra host-side setup.

**Legacy bare-metal path.** `install.sh` installs MySQL natively and would not see the Docker config, so it installs the same file into `/etc/mysql/mysql.conf.d/zzpanel-lowcpu.cnf` and restarts MySQL. If the restart fails it removes the config, restarts again, and aborts the install rather than leaving a broken database.

### 5. Apache request workers

`docker/apache/botpanel-perf.conf` had `MaxRequestWorkers 150`, i.e. up to 150 PHP-FPM processes on a 1 vCPU / 2 GB host, each wanting a MySQL connection. That is both a memory hazard in its own right and the reason `max-connections = 60` would have introduced a new "Too many connections" failure mode. Reduced to `60` to match MySQL, so requests queue at Apache instead of failing at the database. `MaxSpareThreads` moved 75 → 50 to stay within Apache's constraint that it may not exceed `MaxRequestWorkers`.

**This is the one line to veto** if you would rather keep 150 workers. Reverting it means `max-connections` should go back to the MySQL default of 151 so the two stay consistent.

## Verification

`tests/integration/explain-hot-queries.sh` creates a throwaway database, applies the real schema through `sql/sql.php`, seeds 400 users and 2000 orders, then `EXPLAIN`s each hot query and asserts the plan.

```
==> EXPLAIN
    PASS  users by from_id           key=idx_users_from_id            type=ref
    PASS  orders by from_id+code     key=idx_orders_from_id_code      type=ref
    PASS  orders by from_id only     key=idx_orders_from_id_code      type=ref
    PASS  service_factors by from_id key=idx_service_factors_from_id  type=ref
    PASS  factors by code            key=idx_factors_code             type=ref
```

Verified on MySQL 8.0.46 with `lowcpu.cnf` applied:

- **Negative control** — the same queries against tables without the indexes return `type=ALL, key=NULL`. The check genuinely detects a missing index rather than passing vacuously.
- **Tuning applied** — `long_query_time=2.000000`, `max_connections=60`, `slow_query_log=ON`, `slow_query_log_file=/var/log/mysql/slow.log`, `innodb_buffer_pool_size=134217728` (128M), `log_queries_not_using_indexes=OFF`. `events_statements_history` reads `NO` with the config and `YES` without it, proving the file is read.
- **Slow log writes** — a `SELECT SLEEP(3)` was captured as `Query_time: 3.007107  Rows_sent: 1  Rows_examined: 1`.
- **Recorder source is live** — `performance_schema.events_statements_summary_by_digest` is populated.
- **Migration is idempotent** — `2026-10-20-hot-indexes.sql` run three times in a row: no errors, exactly 4 distinct indexes.
- **Lint** — `php -l` clean on PHP 7.4 for all touched PHP files; `bash -n` and `shellcheck` (all severities) clean; both compose files parse and carry the expected mounts.

CI runs the check on every push: the `indexes` job in `.github/workflows/ci.yml` starts a MySQL 8 service and runs the script. ShellCheck covers the new script in the existing `lint` job.

## Deferred (not in this round)

From the flight-recorder plan: the 5s sampler and spike folders, `spike-report.sh`, the Prometheus/exporter/Grafana stack and the nginx `/grafana/` subpath, Sentry, the PHPUnit migration of `tools/logger_selfcheck.php`, the PHP slow-webhook correlation, and the `setup.sh` observability menu. The uncommitted `setup.sh` menu-15 work and `tools/spike-investigate.sh` were reverted so the tree has no menu pointing at files that are never produced.

## Findings not acted on

Recorded so they are not lost, not fixed because each needs its own decision:

1. **Duplicate `users` rows are possible.** `config.php:92-96` does `SELECT ... WHERE from_id = ?` then `INSERT` if empty. Two concurrent webhooks for the same user can both insert, after which `SELECT * FROM users WHERE from_id = ? LIMIT 1` and `UPDATE users SET coin = coin - ? WHERE from_id = ? LIMIT 1` pick an arbitrary row and the coin balance splits across duplicates. A `UNIQUE` index on `users`.`from_id` would close the race, but it cannot be added blindly: an existing deployment with duplicates would fail the `ALTER`, and merging duplicate rows means reconciling `coin`, `count_service`, `count_charge` and `count_warn`. Needs a data audit first.
2. **`send.php` holds a MySQL connection for the whole broadcast.** `SELECT * FROM users` is fetched and then `sendMessage` is called per user, so the result set and its connection stay open for as long as the Telegram API takes to answer — minutes at a few hundred users. An index does not help a full scan that is intentional; the fix is to page the result set and release the connection between batches.
3. **`SELECT * FROM test_account` for a count** (`config.php:577`) reads every row to throw the rows away. `SELECT COUNT(*)` is equivalent and cheap, but it is a code change outside this round's scope.
4. **`orders_archive` drifts from `orders`.** `CREATE TABLE ... LIKE orders` is a one-time copy; later schema changes do not reach the archive.
5. **`mysqldump` runs without `--single-transaction`** (`docker/backup/backup.sh:141`), so a backup taken during writes produces an inconsistent snapshot.

## Open questions

- Is `max-connections = 60` the right ceiling for real traffic, or should it track Apache instead? The two must move together.
- The slow log has no rotation. On a healthy system `long_query_time = 2` keeps it small, but a pathological day could grow it. A logrotate rule with `copytruncate` would close that gap.
- Where should the 2-minute hang actually be attributed? The recorder is the only thing that will answer that with evidence rather than reasoning.
