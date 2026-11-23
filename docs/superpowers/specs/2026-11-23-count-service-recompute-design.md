# Design: `users.count_service` as a cached, recompute-kept count

## Intent

`users.count_service` is meant to answer "how many services does this user have"
without scanning `orders` per request. Today the column drifts: buys `+1` it,
panel-sync deletes orphaned orders without touching it, renewal deliberately
leaves it. We migrate it to a **cached count of `orders` rows**, kept current by
recomputation on the three flows that touch a user's service set: **buy**,
**renewal**, **sync**.

**Why recompute instead of deltas:** one idempotent statement per flow heals
existing drift and can't break if a future write site is missed. Cost is one
indexed `COUNT(*)` per event — negligible against the panel HTTP calls those
flows already make.

## Semantics (owner decision: approach B)

- `count_service` = number of rows in `orders` for the user (`from_id`).
- **Free test accounts are NOT counted** (they live in `test_account`, not
  `orders`). Consequence of B; owner confirmed 2026-11-23.
- Renewal rewrites an existing order row, so the count is unchanged — recomputing
  simply confirms it.

## Approach (chosen, 2026-11-23)

- **A. Deltas** (`+1`/`-1` at write sites): rejected — drifts again at the next
  unhandled write site.
- **B. Recompute** (chosen): `UPDATE users SET count_service = (live COUNT)` after
  each flow's mutation, plus a one-time backfill migration.
- **C. Drop the column:** rejected — the column is kept for external/planned
  readers; the admin display would keep counting live.

## Changes

1. **Migration** `sql/migrations/20261123-002-backfill-count-service.sql`:
   `UPDATE users u SET u.count_service = (SELECT COUNT(*) FROM orders o WHERE o.from_id = u.from_id)`
   — repairs all current drift the moment it runs.

2. **New helper** in `App/Utils/user_counter.php` (db_tools is already loaded
   wherever it's needed; the helper has no top-level side effects so it is
   unit-testable like `renewal_confirm.php`):
   ```php
   function recompute_user_service_count($sql, $from_id) {
       $row = db_row($sql, "SELECT COUNT(*) AS `n` FROM `orders` WHERE `from_id` = ?", 's', array($from_id));
       if ($row === null) { return false; }
       return db_exec($sql, "UPDATE `users` SET `count_service` = ? WHERE `from_id` = ? LIMIT 1", 'is', array((int)$row['n'], $from_id));
   }
   ```
   Per AGENTS.md: runtime failures return `false` (never throw mid-flow);
   contract violations in `db_row`/`db_exec` still throw.

3. **Buy** (`index.php:427`): replace `count_service + 1` in the coin UPDATE
   (statement drops back to `coin` only). Call
   `recompute_user_service_count($sql, $from_id)` after the coin UPDATE.
   Failure handling: log a WARNING (`buy_count_service_stale`) and continue —
   the sale is already delivered; the next buy/renew/sync for that user heals it.

4. **Test account branches** (`index.php:469,495`): drop the `count_service + 1`
   from both statements. No recompute call — the value cannot change (test
   accounts are not in `orders`).

5. **Renewal** (`App/functions/renewal_confirm.php:130`, after `$sql->commit()`):
   call the helper; on failure log WARNING `renewal_count_service_stale` and
   continue (delivery must not depend on it).

6. **Sync** (`App/functions/service_overview.php`): after the loop that archives
   + deletes unknown services, if anything was deleted call the helper once
   with the user id; failure = WARNING `sync_count_service_stale`, no error
   returned (the overview is already computed; the next sync heals).

7. **Admin "view user info"** (`index.php:2911` and the second occurrence at
   ~2983): replace the live `SELECT * FROM orders` count with the cached
   `$info['count_service']` — this is the read side the whole migration is for.

8. **Tests:**
   - `tests/unit/user_counter_test.php`: recompute success (COUNT row →
     `db_exec` with `('is', [n, from_id])`), `db_row` null → returns `false`
     without calling `db_exec`, `db_exec` false → returns `false`.
   - `tests/unit/renewal_confirm_test.php`: stub the helper (resolved at call
     time, like the other helpers); assert it is called exactly once with
     `from_id` on the success path and NOT called on any pre-persist failure
     path (cases 1–10, 13, 13b).
   - No unit test possible for `index.php`/`service_overview.php` changes
     (inline monolith / live panel calls); covered by the existing integration
     smoke path.

## Non-goals

- No transaction wrap of the whole buy flow (separate hardening task).
- No changes to `count_charge`, `coin`, or `test_account` semantics.
- No new indexes (the `orders.from_id` access is already the hot path).

## Risk

Stale-by-one-window: if a recompute fails (DB blip), the count is wrong until
the user's next buy/renew/sync or the backfill reruns. Acceptable — every
failure is logged, and the admin display can fall back to a live count later
if that ever matters.
