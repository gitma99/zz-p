# Cache DRY Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deduplicate the 19 cache helpers and close the review findings with local extractions, no centralization.

**Architecture:** Two tiny includes (`apcu_tools.php`, `db_tools.php`) plus in-place collapses (`get_active_rows`, key builders, TTL constants). Every public function keeps its name and signature (except additive `set()` TTL param), so no call sites change.

**Tech Stack:** PHP 7.4, mysqli, APCu (optional, guarded), PHPUnit-free selfcheck (`tools/logger_selfcheck.php`).

**Spec:** `docs/superpowers/plans/2026-10-14-cache-dry-hardening-spec.md`

## Global Constraints

- PHP 7.4 only: no `?->`, `match`, union types, named args, `str_contains`, enums, readonly.
- `prepare()`/`get_result()` returns always checked; statements always `close()`d.
- `basename()` stays on all temp paths.
- No commits without stakeholder `+` on the presented diff (per-task review gate).
- Verify with a PHP 7.4 runtime (container has none; use Docker): `php -l <file>`, `php tools/logger_selfcheck.php` → `ALL CHECKS PASSED`.

## Review Focus

- Missing panel row: `db_row` on unknown panel name must return null, never fatal on `false->fetch_assoc()`.
- APCu absent: every shared read falls back and warns once, never fatals.
- Mixed-backend temp read (APCu miss → file) returns the same shape as an APCu hit.
- `basename()` collision: `sub/dir` and `dir` keys sharing one namespace is intended; no caller may rely on subpaths.
- Worker loop: per-iteration reset must not break the first task after deploy (empty cache = cold start, legal).

---

### Task 1: APCu guard helpers + tables_exist gaps

**Files:**
- Create: `App/Utils/apcu_tools.php`
- Modify: `config.php:9,13,72,78`, `migrate.php:15`
- Test: `tools/logger_selfcheck.php` (append checks)

**Interfaces:**
- Consumes: nothing new.
- Produces: `apcu_safe_get($k, $default = false)`, `apcu_safe_set($k, $v, $ttl)`, `apcu_safe_del($k)` — each `function_exists`-guarded, fail-open. (`apcu_safe_*`: bare names would collide with the extension.) Used by Tasks 4–5.

- [ ] **Step 1: Write the failing test** — append `check_apcu_helpers()` asserting `apcu_get('nope') === false` default and `apcu_set`/`apcu_get`/`apcu_del` round-trip.
- [ ] **Step 2: Run it to verify it fails**

Run: `php tools/logger_selfcheck.php`
Expected: FAIL with "Call to undefined function apcu_get()"
- [ ] **Step 3: Implement `apcu_get/apcu_set/apcu_del` in `App/Utils/apcu_tools.php`** — guards inside, `require_once` from `config.php:2` block and `temp_tools.php` untouched (adopts later only if trivial).
- [ ] **Step 4: Rewire `config.php:9,13,72,78` through the helpers; add `apcu_del('botpanel_tables_exist')` to `migrate.php` after successful write.**
- [ ] **Step 5: Run checks to verify green**

Run: `php tools/logger_selfcheck.php`
Expected: `ALL CHECKS PASSED`
- [ ] **Step 6: Present uncommitted diff; commit only on stakeholder `+`**

```bash
git add App/Utils/apcu_tools.php config.php migrate.php tools/logger_selfcheck.php
git commit -m "fix(cache): shared APCu guards; cover tables_exist gaps"
```

### Task 2: db_row() + convert three prepared sites

**Files:**
- Create: `App/Utils/db_tools.php`
- Modify: `App/Utils/request_cache.php:59-65`, `App/apis/marzban-api/api.php:6-9`, `custom.php:237-248`
- Test: `tools/logger_selfcheck.php` (extend `FakeSql` with `prepare` stub)

**Interfaces:**
- Consumes: nothing (standalone).
- Produces: `db_row($sql, $query, $types, $params)` → assoc-array-or-null, and `db_exec($sql, $query, $types, $params)` → bool (same guards, for the token UPDATE). Used by Task 5 tests.

- [ ] **Step 1: Write the failing test** — `check_db_row()` asserting a known row returns assoc and an unknown name returns null via the `FakeSql`+`prepare` stub.
- [ ] **Step 2: Run it to verify it fails**

Run: `php tools/logger_selfcheck.php`
Expected: FAIL with "Call to undefined function db_row()"
- [ ] **Step 3: Implement `db_row($sql, $query, $types, $params)` and `db_exec($sql, $query, $types, $params)` in `App/Utils/db_tools.php`** — `prepare()` false-check, `bind_param`, `execute`, (`get_result()` false-check for `db_row`), `close()` in all paths.
- [ ] **Step 4: Convert the three call sites to one-line calls** (`db_row` for panel lookup + token SELECT, `db_exec` for token UPDATE).
- [ ] **Step 5: Run checks to verify green**

Run: `php tools/logger_selfcheck.php`
Expected: `ALL CHECKS PASSED`
- [ ] **Step 6: Present uncommitted diff; commit only on stakeholder `+`**

```bash
git add App/Utils/db_tools.php App/Utils/request_cache.php App/apis/marzban-api/api.php custom.php tools/logger_selfcheck.php
git commit -m "fix(cache): single db_row helper; check+close everywhere"
```

### Task 3: get_active_rows() collapse + set() TTL param

**Files:**
- Modify: `App/Utils/request_cache.php:21-47`
- Test: `tools/logger_selfcheck.php` (existing `check_panel_cache_hit` must stay green)

**Interfaces:**
- Consumes: nothing.
- Produces: `get_active_rows($key, $table)` (private-by-convention); four wrappers keep exact names/keys.

- [ ] **Step 1: Confirm existing test covers behavior** — `check_panel_cache_hit` asserts factory-runs-once + hit-skips-query; no new test needed, record this as the gate.
- [ ] **Step 2: Implement `get_active_rows($key, $table)`; shrink the four functions to one-line wrappers; add `set($k, $v, $ttl = null)` with ignored-TTL docblock.**
- [ ] **Step 3: Run checks to verify green**

Run: `php tools/logger_selfcheck.php`
Expected: `ALL CHECKS PASSED`
- [ ] **Step 4: Present uncommitted diff; commit only on stakeholder `+`**

```bash
git add App/Utils/request_cache.php
git commit -m "fix(cache): collapse get_active_* clones; additive set() TTL"
```

### Task 4: Key builders + TTL constants + temp validation + fallback logging

**Files:**
- Modify: `App/Utils/request_cache.php` (`panel_key`), `config.php` (`membership_key`, `CONFIG_TTL`/`SCHEMA_TTL`/`MEMBER_TTL`), `App/Utils/temp_tools.php` (`FLOW_TTL`, `is_string` decode guard, once-per-request fallback warning)
- Test: `tools/logger_selfcheck.php` (key-builder outputs, TTL values, temp JSON round-trip both backends)

**Interfaces:**
- Consumes: `apcu_*` helpers from Task 1.
- Produces: key-builder functions and TTL constants used by Task 5 tests.

- [ ] **Step 1: Write the failing test** — `check_cache_keys()` asserting `panel_key('x') === 'panel:name:x'`, `temp_key('a/b') === 'botpanel_temp_b'`, TTL constants equal 300/60/60/86400.
- [ ] **Step 2: Run it to verify it fails**

Run: `php tools/logger_selfcheck.php`
Expected: FAIL with "Call to undefined function panel_key()"
- [ ] **Step 3: Implement builders + constants; replace inline keys/TTLs at their owner sites only.**
- [ ] **Step 4: Add `is_string` decode guard in `read_temp_file` with file fall-through; add static-flag once-per-request `Logger::warning` on fallback.**
- [ ] **Step 5: Run checks to verify green**

Run: `php tools/logger_selfcheck.php`
Expected: `ALL CHECKS PASSED`
- [ ] **Step 6: Present uncommitted diff; commit only on stakeholder `+`**

```bash
git add App/Utils/request_cache.php config.php App/Utils/temp_tools.php tools/logger_selfcheck.php
git commit -m "fix(cache): key builders, TTL constants, temp validation, fallback log"
```

### Task 5: Selfcheck completion + docblock vocabulary pointers

**Files:**
- Modify: `tools/logger_selfcheck.php`, docblocks in `request_cache.php`, `temp_tools.php`, `App/apis/marzban-api/api.php`
- Test: full suite

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces: green suite + vocabulary pointers; nothing downstream.

- [ ] **Step 1: Add docblock lines pointing each family at `GLOSSARY.md` term (LookupCache/Memo/FlowState).**
- [ ] **Step 2: Run the full suite to verify green**

Run: `php tools/logger_selfcheck.php`
Expected: `ALL CHECKS PASSED`
- [ ] **Step 3: Present uncommitted diff; commit only on stakeholder `+`**

```bash
git add tools/logger_selfcheck.php App/Utils/request_cache.php App/Utils/temp_tools.php App/apis/marzban-api/api.php
git commit -m "fix(cache): selfcheck completion; vocabulary pointers"
```
