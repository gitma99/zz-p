# count_service Recompute Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep `users.count_service` current as a cached count of the user's `orders` rows, recomputed on buy, renewal, and panel-sync.

**Architecture:** One idempotent helper (`recompute_user_service_count`) built on `db_row`/`db_exec` from `App/Utils/db_tools.php`, called after each flow's mutation; a one-time backfill migration repairs existing drift. Failures log a WARNING and continue — the count self-heals on the user's next touch.

**Tech Stack:** PHP 7.4, mysqli via `db_tools.php`, MySQL InnoDB, zero-dependency unit tests (`tests/unit/run.sh`), integration tests via `docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tests`.

**Spec:** `docs/superpowers/specs/2026-11-23-count-service-recompute-design.md`

## Global Constraints

- Runtime/DB failures in helpers return `false`/`null` (never throw); only contract violations (param count vs `?`) throw `InvalidArgumentException` — this is `db_tools.php`'s existing contract.
- Test stubs in `tests/unit/` must mirror real driver behavior (per AGENTS.md): the `db_row`/`db_exec` stubs in `tests/unit/renewal_confirm_test.php` already validate param counts — keep that.
- `from_id` is compared as a string in `orders` (schema: `varchar`), integer in `users` (`bigint`): always bind `from_id` with type `s` in the COUNT query, `is` in the UPDATE (int count, string from_id).
- No new dependencies, no new indexes, no changes to `coin`/`count_charge`/`test_account` semantics.

## Review Focus

- **Service deleted out-of-band from a panel, user never re-syncs:** count stays high until their next buy/renew/sync. Acceptable per spec (Risk section); a `buy_count_service_stale`-style log must exist so an operator can spot chronic DB failure.
- **`from_id` with leading zeros / non-numeric strings in `orders`:** the recompute compares the raw string in COUNT and the raw value in UPDATE — both sides use the same value, so they must stay in lockstep; never "fix" one side to int.
- **Recompute called with no `users` row for that `from_id`:** UPDATE matches 0 rows, returns true, no error. No special handling needed — verified by integration test, not unit.
- **`all_service_count__` admin callback and the task worker both run sync:** the recompute must run inside `fetch_user_service_overview` (where deletion happens), not at either caller, so both entry points are covered — verify no call was added to `index.php:1705` or `workers/task_worker.php`.
- **Renewal persistence failure must NOT trigger a recompute:** the helper call sits after `$sql->commit()`; a rollback path that calls it would assert a count the transaction never committed.

---

### Task 1: Backfill migration + `recompute_user_service_count` helper

**Files:**
- Create: `sql/migrations/20261123-002-backfill-count-service.sql`
- Create: `App/Utils/user_counter.php`
- Modify: `custom.php` (add one `require_once`)
- Create: `tests/unit/user_counter_test.php`
- Create: `tests/integration/user_counter_test.php`

**Interfaces:**
- Consumes: `db_row($sql, $query, $types, $params)` / `db_exec(...)` from `App/Utils/db_tools.php` (already loaded by `config.php`/`custom.php`).
- Produces: `bool recompute_user_service_count(mysqli $sql, $from_id)` — returns `true` only when both the COUNT and the UPDATE succeed. Later tasks call it exactly as `recompute_user_service_count($sql, $from_id)`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/unit/user_counter_test.php` in the house style of `tests/unit/db_tools_test.php` (self-contained, stubs `db_row`/`db_exec` locally, `require_once __DIR__ . '/helper.php'`, ends with `echo "OK user_counter_test.php"; exit(0);`).

Stubs:
```php
$GLOBALS['stub_row'] = null;   // db_row return value (default: array('n' => 3))
$GLOBALS['exec_result'] = true;
$GLOBALS['exec_calls'] = array();
function db_row($sql, $query, $types = '', $params = []) {
    $expected = substr_count($query, '?');
    if ($expected !== count($params)) {
        throw new InvalidArgumentException("db_row: expected {$expected} param(s), " . count($params) . " given for query: {$query}");
    }
    if ($GLOBALS['stub_row'] !== null) return $GLOBALS['stub_row'];
    return array('n' => 3);
}
function db_exec($sql, $query, $types = '', $params = []) {
    $expected = substr_count($query, '?');
    if ($expected !== count($params)) {
        throw new InvalidArgumentException("db_exec: expected {$expected} param(s), " . count($params) . " given for query: {$query}");
    }
    $GLOBALS['exec_calls'][] = array('query' => $query, 'types' => $types, 'params' => $params);
    return $GLOBALS['exec_result'];
}
```
Then `require_once __DIR__ . '/../../App/Utils/user_counter.php';` (module must have no top-level side effects, mirroring `renewal_confirm.php`).

Cases:
1. **Success:** `recompute_user_service_count($sql, '1001')` returns `true`; exactly one `db_exec` call with query ``UPDATE `users` SET `count_service` = ? WHERE `from_id` = ? LIMIT 1``, types `'is'`, params `[3, '1001']` (int 3, string '1001' — `assert_eq` on the array).
2. **Count query shape:** the recorded `db_row` call (record queries in a parallel `$GLOBALS['row_calls']`) is ``SELECT COUNT(*) AS `n` FROM `orders` WHERE `from_id` = ?`` with types `'s'` and params `['1001']`.
3. **db_row null → false, no UPDATE:** `$GLOBALS['stub_row'] = null;` → returns `false`, `exec_calls` empty.
4. **db_exec false → false:** `$GLOBALS['exec_result'] = false;` → returns `false`, one exec call recorded.

- [ ] **Step 2: Run the test, confirm it fails**

Run: `php tests/unit/user_counter_test.php`
Expected: FATAL — `require_once ... user_counter.php` fails (file does not exist).

- [ ] **Step 3: Implement the helper**

`App/Utils/user_counter.php` — header comment line (one line, no side effects, db_tools loaded by caller), then:
```php
function recompute_user_service_count($sql, $from_id)
{
    $row = db_row($sql, "SELECT COUNT(*) AS `n` FROM `orders` WHERE `from_id` = ?", 's', array($from_id));
    if ($row === null) { return false; }
    return db_exec($sql, "UPDATE `users` SET `count_service` = ? WHERE `from_id` = ? LIMIT 1", 'is', array((int)$row['n'], $from_id));
}
```

- [ ] **Step 4: Wire the require**

In `custom.php`, after line 6 (`require_once __DIR__ . "/App/functions/renewal_confirm.php";`), add:
```php
require_once __DIR__ . "/App/Utils/user_counter.php";
```
This makes the helper available in `index.php` (which loads `custom.php` at line 56) and in `workers/task_worker.php` (via `config.php` → `include_once 'custom.php'` at config.php:355).

- [ ] **Step 5: Run the test, confirm it passes**

Run: `php tests/unit/user_counter_test.php`
Expected: `OK user_counter_test.php`, exit 0.

- [ ] **Step 6: Write the integration test**

Create `tests/integration/user_counter_test.php`, modeled on `tests/integration/db_tools_test.php` (same `.env.test` loading, connect loop, `register_shutdown_function` teardown). In the test DB:
1. `CREATE TABLE users (row INT AUTO_INCREMENT PRIMARY KEY, from_id BIGINT NOT NULL, count_service INT DEFAULT 0)`, `CREATE TABLE orders (row INT AUTO_INCREMENT PRIMARY KEY, from_id VARCHAR(20) NOT NULL, code VARCHAR(20) NOT NULL)`.
2. Insert users `1001` (with 2 orders), `1002` (no orders), `1003` (no `users` row → covered by the missing-row case: call the helper with `'9999'`).
3. Assert `recompute_user_service_count` returns `true` for 1001 and 1002; `SELECT count_service FROM users WHERE from_id = 1001` is 2; 1002 is 0.
4. Insert a third order for 1001, call again, assert 3.
5. Call with `'9999'` (no user row): returns `true` (UPDATE matched 0 rows, no error).
End: drop both tables, `echo "OK user_counter_test.php";`.

- [ ] **Step 7: Run the full unit suite**

Run: `bash tests/unit/run.sh`
Expected: all PASS including the new file.

- [ ] **Step 8: Commit**

```bash
git add App/Utils/user_counter.php custom.php tests/unit/user_counter_test.php tests/integration/user_counter_test.php
git commit -m "feat: add recompute_user_service_count helper (orders-based cached count)"
```

---

### Task 2: Backfill migration

**Files:**
- Create: `sql/migrations/20261123-002-backfill-count-service.sql`

**Interfaces:**
- Consumes: the migration runner's existing `schema_migrations` tracking (see `migrate.php`; filename-keyed, no runner changes needed).
- Produces: nothing for code; repairs `users.count_service` in every live DB on next deploy.

- [ ] **Step 1: Write the migration**

```sql
-- Backfill users.count_service from the live orders table.
-- Run once per database (tracked in schema_migrations); safe to rerun —
-- the statement is idempotent and cheap enough against the hot-path index.
UPDATE `users` u
SET u.`count_service` = (SELECT COUNT(*) FROM `orders` o WHERE o.`from_id` = u.`from_id`);
```

Note: `orders.from_id` is `varchar`, `users.from_id` is `bigint` — MySQL coerces on the join; this is the same comparison every existing query in the codebase already performs (`WHERE from_id = '$from_id'` with a numeric string).

- [ ] **Step 2: Sanity-check against the test DB**

Run the integration suite (which spins up the compose MySQL) to confirm nothing in the migration's shape breaks on an empty schema — the migration only runs via `migrate.php`, so this is a manual check:
Run: `docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tests`
Expected: all integration tests PASS.

- [ ] **Step 3: Commit**

```bash
git add sql/migrations/20261123-002-backfill-count-service.sql
git commit -m "feat: backfill users.count_service from orders"
```

---

### Task 3: Renewal flow recompute + unit tests

**Files:**
- Modify: `App/functions/renewal_confirm.php:130-139`
- Modify: `tests/unit/renewal_confirm_test.php`

**Interfaces:**
- Consumes: `recompute_user_service_count($sql, $from_id): bool` (Task 1).
- Produces: nothing new; `renewal_confirm($from_id)` gains one post-commit call.

- [ ] **Step 1: Update the unit test stubs and add assertions**

In `tests/unit/renewal_confirm_test.php`:
1. Add the stub (resolves at call time, like the other helpers — do NOT require the real module):
```php
$GLOBALS['recompute_calls'] = array();
function recompute_user_service_count($sql, $from_id) {
    $GLOBALS['recompute_calls'][] = $from_id;
    return true;
}
```
and `$GLOBALS['recompute_calls'] = array();` in `reset_case()`.
2. In **case 15** (success), after the existing tx assertions add:
```php
assert_eq(array(7), $GLOBALS['recompute_calls'], 'case15: recompute called exactly once with from_id, after commit');
```
(The call sits after `$sql->commit()` in the implementation, so "called once on the success path" plus "never called on rollback paths" — asserted in step 3's case list — pins the placement.)
3. In **cases 1–10 and 13, 13b** (every path that returns before or during persistence failure), add:
```php
assert_eq(array(), $GLOBALS['recompute_calls'], 'caseN: no recompute on <path> failure');
```

- [ ] **Step 2: Run the test, confirm the new assertions fail**

Run: `php tests/unit/renewal_confirm_test.php`
Expected: FAIL — case15 recompute assertion (helper never called).

- [ ] **Step 3: Implement**

In `App/functions/renewal_confirm.php`, inside the step-8 persist block, immediately after `$sql->commit();` (line 130) and still inside the `try`:
```php
if (!recompute_user_service_count($sql, $from_id)) {
    Logger::warning('renewal_count_service_stale', array('from_id' => $from_id, 'code' => $code_base));
}
```
Placement matters: after commit (never on a rolled-back tx) and inside the outer try (an unexpected throw still hits `renewal_confirm_unhandled`).

- [ ] **Step 4: Run the test, confirm it passes**

Run: `php tests/unit/renewal_confirm_test.php`
Expected: `OK renewal_confirm_test.php`.

- [ ] **Step 5: Run the full unit suite**

Run: `bash tests/unit/run.sh`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add App/functions/renewal_confirm.php tests/unit/renewal_confirm_test.php
git commit -m "feat: recompute count_service after renewal commit"
```

---

### Task 4: Buy + test-account + admin-display changes in `index.php`

**Files:**
- Modify: `index.php:427,469,495,2911,2983-2984`

**Interfaces:**
- Consumes: `recompute_user_service_count($sql, $from_id): bool` (Task 1), available via `custom.php`.
- Produces: `users.count_service` maintained on the buy path; admin "view user info" reads the cached value.

- [ ] **Step 1: Buy — coin UPDATE drops the increment; add recompute**

`index.php:427`, replace:
```php
$sql->query("UPDATE `users` SET `coin` = coin - $price, `count_service` = count_service + 1 WHERE `from_id` = '$from_id' LIMIT 1");
```
with:
```php
$sql->query("UPDATE `users` SET `coin` = coin - $price WHERE `from_id` = '$from_id' LIMIT 1");
if (!recompute_user_service_count($sql, $from_id)) {
    Logger::warning('buy_count_service_stale', array('from_id' => $from_id, 'code' => $code_base));
}
```
Both the marzban and sanayi buy branches funnel through this one line; `$code_base` is set at the buy-flow entry (`index.php:270`/`323`) and is in scope for both branches.

- [ ] **Step 2: Test-account branches — drop the increment, no recompute**

`index.php:469` and `index.php:495`, replace:
```php
$sql->query("UPDATE `users` SET `count_service` = count_service + 1, `test_account` = 'yes' WHERE `from_id` = '$from_id'");
```
with:
```php
$sql->query("UPDATE `users` SET `test_account` = 'yes' WHERE `from_id` = '$from_id'");
```
No helper call: test accounts are not in `orders`, so the count cannot change (spec: excluded).

- [ ] **Step 3: Admin "view user info" — read the cached count**

`index.php:2911` and `index.php:2983` (the second `info_user` branch), replace in both:
```php
$count_service = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$text'")->num_rows ?? 0;
```
with:
```php
$count_service = (int)($info['count_service'] ?? 0);
```
(`$info` is the fetched `users` row in both branches; line 2984's commented-out `// $count_service = $info['count_service'] ?? 0;` can be deleted.)

- [ ] **Step 4: Lint-check the touched file**

Run: `php -l index.php`
Expected: `No syntax errors detected in index.php`.

- [ ] **Step 5: Manual smoke checklist (send to owner, dev bot)**

1. Buy a service → `users.count_service` for the user = `SELECT COUNT(*) FROM orders` (verify via phpMyAdmin/CLI).
2. Renew that service → count unchanged.
3. Delete the service on the Marzban panel, then trigger the service overview (admin `all_service_count__` or user `calc_overview__`) → count decrements by 1 after the archive+delete.
4. View user info (admin) → shows the cached count, no `orders` count query in the DB general log.

- [ ] **Step 6: Commit**

```bash
git add index.php
git commit -m "feat: maintain count_service on buy; read cached count in admin view"
```

---

### Task 5: Sync recompute in `fetch_user_service_overview`

**Files:**
- Modify: `App/functions/service_overview.php:124-205` (the archive+delete block)

**Interfaces:**
- Consumes: `recompute_user_service_count($sql, $from_id): bool` (Task 1). Both callers (`index.php:1705` `all_service_count__` callback and `workers/task_worker.php:79`) get the behavior for free — the hook lives here, not at either caller.
- Produces: count healed after panel-sync deletes orphaned orders.

- [ ] **Step 1: Implement**

In `App/functions/service_overview.php`, in the final archive+delete `foreach` (lines ~129-205): track deletions with a counter `$deleted = 0;` incremented after each successful `$sql->commit();` in that loop. After the loop closes, before the final `return`, add:
```php
if ($deleted > 0 && !recompute_user_service_count($sql, $target_user_id)) {
    error_log("sync_count_service_stale user=$target_user_id deleted=$deleted");
}
```
Use `error_log`, not `Logger::` — this file is included by the task worker, whose environment resolves `Logger` differently (worker builds its own logging via `log_message`); `error_log` works in both web and worker contexts and lands in the same sink. Do not add an error return: the overview data is already computed, and the next sync heals a stale count.

- [ ] **Step 2: Lint-check**

Run: `php -l App/functions/service_overview.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Confirm both entry points covered**

Run: `grep -n "fetch_user_service_overview" index.php workers/task_worker.php`
Expected: exactly the two call sites (`index.php:1709` admin callback, `workers/task_worker.php:79`); no recompute call added to either file.

- [ ] **Step 4: Run the full unit suite (regression)**

Run: `bash tests/unit/run.sh`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add App/functions/service_overview.php
git commit -m "feat: recompute count_service after panel-sync deletes"
```

---

## Post-implementation

- Run integration: `docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tests`.
- Deploy note: run `php migrate.php` (or the project's usual migration path) so the backfill lands before the new code's reads go live; the reads (Task 4 Step 3) are safe either way since the backfill and the recompute converge on the same value.
