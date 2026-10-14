# Cache DRY Hardening Spec (no centralization)

**Status:** agreed with stakeholder (grill rounds 1–2, Q1–Q10 all yes).
**Vocabulary:** `GLOSSARY.md` — LookupCache / Memo / FlowState.
**Background:** `docs/cache-system.md` (11 layers mapped), branch `fix/cache-security-invalidation` (9 fixes, reviewed).

## Goal

Remove duplication and close correctness gaps in the 19 cache helpers with small local extractions. No central `Cache` class, no storage-backend unification, no rewrite of the 31 `index.php` call sites.

## Non-goals

- No central cache manager / facade.
- No single storage backend (lifetimes stay: per-request, 60–300s APCu, 86400s flow).
- No mass-wiring of invalidation into `index.php` admin handlers (ruled out in fix 4: each re-queries directly after its own write).
- No new composer dependencies. No PHP 8 syntax (runtime is php7.4-fpm).

## Requirements

- **R1 (Q1):** APCu guards live in one place: new `App/Utils/apcu_tools.php` with `apcu_safe_get($k, $default)`, `apcu_safe_set($k, $v, $ttl)`, `apcu_safe_del($k)`, each holding its own `function_exists` check. (`apcu_safe_*` prefix: bare `apcu_get/set/del` names would fatally collide with the APCu extension when installed.) All existing guarded call sites may adopt them; the two unguarded `botpanel_tables_exist` calls (`config.php:72,78`) MUST.
- **R2 (Q9):** `migrate.php` deletes `botpanel_tables_exist` after schema writes (closes Spec-review finding).
- **R3 (Q3):** One `db_row($sql, $query, $types, $params)` helper in new `App/Utils/db_tools.php`, returning assoc-array-or-null, checking `prepare()` success and calling `close()`. The three prepared sites (`request_cache.php` panel lookup, `api.php` token SELECT, `custom.php` token UPDATE) MUST use it.
- **R4 (Q10):** One private `get_active_rows($key, $table)` in `request_cache.php`; the four `get_active_*` functions become one-line wrappers keeping exact names and keys.
- **R5 (Q6):** Per-family key builders next to owners: `panel_key($name)` in `request_cache.php`, `membership_key($chat, $user)` in `config.php`; `temp_key()` (exists) unchanged. Config keys as constants beside owners.
- **R6 (Q2):** TTLs as named constants beside owners: `CONFIG_TTL 300`, `SCHEMA_TTL 60`, `MEMBER_TTL 60`, `FLOW_TTL 86400`. Values unchanged.
- **R7 (Q7):** `RequestCache::set($k, $v, $ttl = null)` accepts an ignored `$ttl` (memory driver has no expiry), documented in docblock.
- **R8 (temp validation):** `read_temp_file` validates `is_string` before `json_decode`; decode failure falls through to the file backend.
- **R9 (Q8):** APCu-absent fallback emits one `Logger::warning` per request via the existing `Logger` (not per call).
- **R10 (Q5):** `tools/logger_selfcheck.php` gains: key-builder outputs, TTL constant values, `db_row` against `FakeSql` (extended with a `prepare` stub), temp JSON round-trip on both backends.
- **R11 (Q4):** Vocabulary split stands: LookupCache / Memo / FlowState per `GLOSSARY.md` (done); docblocks on the three families point at it.

## Constraints (global, every task)

- PHP 7.4 only: no nullsafe, match, union types, named args, `str_contains`, enums.
- mysqli only; `get_result()` requires mysqlnd (Ubuntu `php7.4-mysql` provides it) — still guard `prepare()`/`get_result()` returns.
- `basename()` on all temp paths (traversal guard, fix 6) stays.
- Workflow: implement one task, present uncommitted diff, wait for stakeholder `+`, then commit. No commits without approval.
- Verification needs a PHP 7.4 runtime (`php -l`, `php tools/logger_selfcheck.php`); this dev container has no `php` binary, so run via Docker (`docker-compose run --rm app …`) or the deploy host.
