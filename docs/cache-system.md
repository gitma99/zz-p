# ZZPanel Cache System

This document maps every cache layer in ZZPanel, including mechanism, keys, TTL, owning files, invalidation, and risks.

---

## 1. Per-Request In-Memory Cache (`RequestCache` / `cached_query`)

Mechanism: plain PHP array alive only for one webhook/CLI request. No TTL, no cross-request sharing, no delete API.

- Class stores in `$store` array with `has`/`get`/`set` (`App/Utils/request_cache.php:2-8`).
- Global singleton `$cache = new RequestCache()` (`App/Utils/request_cache.php:10`).
- `cached_query($key, $fn)` returns stored value on hit, else runs `$fn` once and stores (`App/Utils/request_cache.php:12-18`).
- Keys:
  - `panels:active` (`App/Utils/request_cache.php:21`).
  - `category:active` (`App/Utils/request_cache.php:28`).
  - `category_date:active` (`App/Utils/request_cache.php:35`).
  - `category_limit:active` (`App/Utils/request_cache.php:42`).
  - `panel:name:<name>` (`App/Utils/request_cache.php:57`).
- Queries cached:
  - `SELECT * FROM panels WHERE status = 'active'` (`App/Utils/request_cache.php:22`).
  - `SELECT * FROM category WHERE status = 'active'` (`App/Utils/request_cache.php:29`).
  - `SELECT * FROM category_date WHERE status = 'active'` (`App/Utils/request_cache.php:36`).
  - `SELECT * FROM category_limit WHERE status = 'active'` (`App/Utils/request_cache.php:43`).
  - `SELECT * FROM panels WHERE name = '$name'` (`App/Utils/request_cache.php:58`).
- `get_panel_by_name()` shortcut: if `panels:active` already cached, linear-scans it and seeds `panel:name:<name>` without a query (`App/Utils/request_cache.php:49-55`).
- Call sites:
  - `get_active_panels()` in buy flow (`index.php:193`), renewal flow (`custom.php:293`), buy-service (`index.php:193`).
  - `get_active_categories()` (`index.php:215`, `custom.php:318`).
  - `get_panel_by_name($location)` (`index.php:266`, `index.php:274`, `custom.php:342`, `custom.php:21`, `custom.php:175`).
  - `get_active_date_plans()` (`index.php:1423`), `get_active_limit_plans()` (`index.php:1439`).
- Self-check covers factory-runs-once, active-list hit skips query, inactive-name miss then caches (`tools/logger_selfcheck.php:171-194`).

Invalidation: none. No `apcu_delete`, `apcu_clear`, `opcache_reset`, or `RequestCache` clear exists in runtime code (repo grep for `apcu_clear|apc_clear|opcache_reset|opcache_invalidate|cache_clear|clear.*cache` returns no matches). Same-request `UPDATE panels SET status` (`index.php:2250`, `index.php:2252`), flow/protocol/rename updates (`index.php:2430-2481`), and category status toggles (`index.php:2603-2651`) do not clear `RequestCache`.

Risks:
- Stale-within-request: admin disables a panel mid-request, earlier `get_active_panels()` result still served.
- SQL injection: `get_panel_by_name()` interpolates `$name` unescaped (`App/Utils/request_cache.php:58`); same pattern in `config.php:83`, `custom.php:9`, `custom.php:242`, `index.php:209`, `index.php:226`.

---

## 2. APCu Config Cache (`botpanel_config`, 300s)

Mechanism: APCu shared memory across FPM workers on one host. Semantics: `apcu_fetch` returns `false` on miss, `apcu_store($key,$val,$ttl)` expires after TTL (see https://www.php.net/manual/en/function.apcu-store.php).

- Fetch `apcu_fetch('botpanel_config')` (`config.php:9`).
- On miss, read `$BOT_CONFIG_PATH` (`App/vars.php:5`, `config.php:11`), `json_decode`, store 300s (`config.php:11-13`).
- `$BOT_CONFIG_PATH` is `realpath(__DIR__ . '/../bot_config.json')` (`App/vars.php:5`); generated at container start (`docker-entrypoint.sh:13-18`) and from env in backup image (`docker/backup/backup.sh:129-138`).

Invalidation: none. `modify_bot_config()` rewrites `bot_config.json` via `file_put_contents` without `apcu_delete` (`custom.php:57-61`). Same for `App/Utils/config_tools.php:12-16` write path and `migrate.php:15` rewrite. Stale config served up to 300s.

Risks:
- Unguarded calls: `config.php:9` and `config.php:13` call `apcu_fetch`/`apcu_store` without `function_exists` guards, unlike `config.php:185`, `config.php:193`, `config.php:207`, `config.php:219` and all of `App/Utils/temp_tools.php:7-27`. Missing `php-apcu` fatals here. Image does install it (`Dockerfile:17`, `install.sh:212`).
- Dynamic fields (`version`, `domain` from `HTTP_HOST`/`SCRIPT_FILENAME`) applied after fetch (`config.php:15-16`), so not cached — safe.

---

## 3. APCu Schema-Exists Flag (`botpanel_tables_exist`, 60s)

Mechanism: avoids `information_schema` query per webhook.

- `apcu_fetch('botpanel_tables_exist', $_apcu_hit) ?: false` (`config.php:72`).
- On miss, counts `users,admins,test_account_setting` in `DATABASE()` and stores bool 60s (`config.php:74-78`).

Invalidation: TTL only. No delete. Risk is low: false-negative (tables created, flag still false) delays onboarding up to 60s; false-positive (tables dropped, flag still true) lets later queries fail until expiry.

---

## 4. APCu Telegram Membership Memo (`apcu:join:*`, `apcu:joinSend:*`, 60s)

Mechanism: memoizes `getChatMember` API calls per `(chat, user)`.

- `isJoin($from_id)`: key `join:<chat_id>:<from_id>`, stored as `apcu:<key>` (`config.php:184-193`).
  - Fetch guarded by `function_exists('apcu_fetch')` (`config.php:185`), fetch `apcu:<ck>` (`config.php:187`), hit means `continue` (skip API) (`config.php:188`).
  - Miss calls `getChatMember($id['chat_id'], $from_id)` (`config.php:190`), `left` returns `false` immediately without storing (`config.php:192`); otherwise stores `$status` 60s (`config.php:193`).
- `joinSend($from_id)`: key `joinSend:<link>:<from_id>`, stored as `apcu:<key>` (`config.php:206-219`).
  - Same guard/fetch pattern (`config.php:207-211`); miss calls `getChatMember($link, $from_id)` (`config.php:212`).
  - `left` appends join button and `continue` without storing (`config.php:213-216`); API failure (`!ok`) skips without storing (`config.php:218`); else stores full `$chat_member` array 60s (`config.php:219`).
- `getChatMember` itself is thin `bot('getChatMember', $params)` wrapper with warning/info logs (`App/apis/telegram-api/api/get_chat_member.php:4-15`).

Invalidation: TTL only (60s). No delete on lock-channel add/remove (`index.php:3244` context) or ban/unban.

Risks:
- Stale-allow: user leaving a force-join channel still passes `isJoin` up to 60s.
- Stale-deny inverted in `joinSend`: only non-`left` results cached, so a `left` user is re-checked every message (API cost), while a joined user keeps skipped buttons for 60s after leaving.
- Double prefix `apcu:join:...` is intentional namespacing, not a bug, but confusing when grepping `apcu_*`.
- `joinSend` stores whole API response array; `isJoin` stores bare status string — same 60s TTL, different payload shapes.

---

## 5. Temp-File Hybrid Cache (`botpanel_temp_*`, 86400s + filesystem fallback)

Mechanism: APCu first, `temp/` dir fallback. Used for multi-step conversation state.

- `write_temp_file`: if `apcu_store` exists, `apcu_store('botpanel_temp_'.$file_name, $content, 86400)` and return (`App/Utils/temp_tools.php:6-8`); else `write_file($content, $TEMP_DIR_PATH."/".$file_name, $json_encode)` (`App/Utils/temp_tools.php:10-11`).
- `read_temp_file`: if `apcu_fetch` exists, `apcu_fetch('botpanel_temp_'.$file_name, $success)`, on success `return [$data, false]` (`App/Utils/temp_tools.php:14-19`); else `open_file($TEMP_DIR_PATH."/".$file_name, $json_decode)` (`App/Utils/temp_tools.php:21-22`).
- `delete_temp_file`: `apcu_delete('botpanel_temp_'.$file_name)` if exists (`App/Utils/temp_tools.php:25-28`), then `unlink($TEMP_DIR_PATH."/".$file_name)` if present (`App/Utils/temp_tools.php:29-33`).
- `$TEMP_DIR_PATH = realpath(__DIR__ . '/../temp')` (`App/vars.php:6`); file helpers are `file_put_contents` with optional `json_encode` (`App/Utils/write_file.php:2-13`) and `file_get_contents` with optional `json_decode` returning `[$data, $err]` tuple (`App/Utils/open_file.php:2-17`).
- Key naming: raw `$file_name` appended, e.g. `<from_id>-location.txt` (`index.php:222`), `<from_id>-plan.txt` (`index.php:240`), `srv-list--<from_id>.txt` (`index.php:698`, `index.php:891`), `renewal-service-<user_id>.json` (`custom.php:252-260`).

Call sites:
- Buy flow writes location/plan (`index.php:222`, `index.php:240`), reads them (`index.php:249`, `index.php:255`), deletes on cancel (`index.php:187-188`).
- Service-list paging writes index map (`index.php:698`, `index.php:891`), reads + deletes on select (`index.php:1205-1206`); broadcast msg-id list read + deleted (`index.php:587-593`); stray `$file_name` delete (`index.php:362`).
- Renewal flow `read_renewal_json`/`write_renewal_json` (`custom.php:250-261`), deletes on finish/cancel (`custom.php:399`, `custom.php:454-455`).

Invalidation: explicit `delete_temp_file` at flow ends above; no global clear; APCu entries otherwise expire after 86400s.

Risks:
- Flag ignored on APCu path: `write_temp_file($array, $name, true)` passes raw array to APCu but JSON-encodes on filesystem (`App/Utils/temp_tools.php:6-11`); `read_temp_file($name, true)` skips `json_decode` on APCu hit (`App/Utils/temp_tools.php:14-19`). Works only because both sides stay on same backend per host. Mixed-backend read (APCu miss → file) after APCu write leaves orphan file never written.
- `delete_temp_file` cleans both stores (`App/Utils/temp_tools.php:25-33`) — correct.
- `realpath` returns `false` if `temp/` missing (`App/vars.php:6`), then fallback path becomes `/<file>` — check dir exists on deploy.
- User-controlled `$file_name` contains `$from_id`/`$text`-derived names; no traversal sanitization in `temp_tools.php:6-33`.

---

## 6. Local Function-Scope Cache (`$panel_cache` in `get_users_usage`)

Mechanism: plain PHP array local to one function call, alive for loop duration only.

- `$panel_cache = []` (`custom.php:15`), keyed by `$config_location`, seeded via `get_panel_by_name($config_location)` on first use (`custom.php:20-22`), reused as `$panel` (`custom.php:23-24`).
- Avoids N+1 panel lookups when one user owns many orders on same panel (`custom.php:9`, `custom.php:16-24`).

Invalidation: N/A (call-scoped). Inherits `RequestCache` staleness via `get_panel_by_name`.

---

## 7. Marzban Token Memo (`global $token_tested`, request-scoped)

Mechanism: boolean global, not APCu, not persistent.

- `global $sql, $token_tested` (`App/apis/marzban-api/api.php:4`); always re-queries `SELECT * FROM panels WHERE name = '$panel_name'` (`App/apis/marzban-api/api.php:5`).
- If `isset($token_tested)`, return stored `$panel['token']` without `/api/system` probe (`App/apis/marzban-api/api.php:8-9`).
- Else probe `$panel_url/api/system` with Bearer token (`App/apis/marzban-api/api.php:12-20`); version present sets `$token_tested = true` and returns old token (`App/apis/marzban-api/api.php:23-25`); else re-login via `reset_panel_panel_token` (`App/apis/marzban-api/api.php:27-30`), which `UPDATE panels SET token` (`custom.php:242`).
- Callers: `getUserInfo($code, get_marzban_panel_token($location), ...)` (`index.php:267`, `custom.php:24`); retry path calls `get_marzban_panel_token` twice (`index.php:271-275`).

Invalidation: none within request after first success — second call for a different panel still skips validation and trusts DB token. New process/request resets (global unset).

Risks:
- Cross-panel trust: validating panel A marks all later panels valid for that request.
- DB write (`custom.php:242`) interpolates `$new_token`/`$panel_name` unescaped; token comes from remote panel response (`custom.php:239-242`).
- `get_marzban_panel_token` query itself interpolates `$panel_name` (`App/apis/marzban-api/api.php:5`).

---

## 8. OPcache + APCu Runtime Tuning

Mechanism: PHP engine + shared-memory config, not app code. See https://www.php.net/manual/en/opcache.configuration.php.

- Active FPM config (`docker/php/botpanel-perf.ini:2-16`), copied in image (`Dockerfile:42`), duplicated for bare-metal in `install.sh:351-368`:
  - `opcache.enable=1`, `memory_consumption=128`, `interned_strings_buffer=16`, `max_accelerated_files=10000` (`docker/php/botpanel-perf.ini:2-5`).
  - `opcache.revalidate_freq=2`, `opcache.validate_timestamps=1` (`docker/php/botpanel-perf.ini:6-7`) — source edits apply after ≤2s.
  - `apc.enabled=1`, `apc.enable_cli=1`, `apc.shm_size=32M` (`docker/php/botpanel-perf.ini:9-11`) — enables all APCu layers above, including CLI workers.
  - `memory_limit=128M`, `max_execution_time=120`, `post_max_size=16M`, `upload_max_filesize=16M` (`docker/php/botpanel-perf.ini:13-16`).
- `php-apcu` installed in image (`Dockerfile:17`) and bare-metal (`install.sh:212`); Apache `mpm_event`/`deflate` tuning alongside (`install.sh:375-399`).

Invalidation: OPcache timestamp check every 2s; no manual `opcache_reset` in repo. APCu data survives deploys until container/FPM restart or TTL.

---

## 9. Docker Build Cache (CI only, no runtime effect)

Mechanism: registry cache for image builds. See https://docs.docker.com/build/cache/backends/.

- `cd.yml` app + backup builds use `cache-from: type=registry,ref=...:nightly` + `cache-to: type=inline` (`/.github/workflows/cd.yml:65-66`, `/.github/workflows/cd.yml:76-77`).
- `release.yml` builds use `cache-from: ...:nightly` with no `cache-to` (`/.github/workflows/release.yml:68`, `/.github/workflows/release.yml:77`).
- Path filter decides which image rebuilds (`/.github/workflows/cd.yml:20-30`).

Invalidation: nightly tag moves on `main` push; releases reuse last nightly layers. No app-data caching.

---

## 10. Workers, Tools, Entry Points

- `workers/task_worker.php` requires `config.php` (`workers/task_worker.php:6`), so inherits `RequestCache` + APCu config/tables/membership layers. It loops forever (`workers/task_worker.php:18`), claims tasks with `FOR UPDATE SKIP LOCKED` (`workers/task_worker.php:24`), recovers stale `running` after 5 min (`workers/task_worker.php:20`). With `apc.enable_cli=1` (`docker/php/botpanel-perf.ini:10`), APCu entries persist across loop iterations in that process, and the global `RequestCache` `$cache` is never reset between tasks — panel/category rows go stale for worker lifetime. No cache calls directly in worker; staleness comes via included code.
- `tools/logger_selfcheck.php` exercises `cached_query`/`get_active_panels`/`get_panel_by_name` with a fake SQL driver (`tools/logger_selfcheck.php:171-194`); no APCu/temp coverage there.
- `tools/restore_marzban_orders.php`, `tools/create_bulk_accounts.php` contain no `cached_query|temp_file|apcu|RequestCache|get_panel|get_active` matches.
- `index.php` health-check path doubles as Docker `HEALTHCHECK` (`Dockerfile:57-58` → `index.php`).

---

## 11. Invalidation Summary and Stale-Data Risks

No centralized invalidation. Only deletes in repo are temp-file deletes (`App/Utils/temp_tools.php:26-27`, call sites `index.php:187-188`, `index.php:362`, `index.php:593`, `index.php:1206`, `custom.php:399`, `custom.php:454-455`).

| Write path | Cache affected | Cleared? |
|---|---|---|
| Panel add/toggle/rename/flow/protocols (`index.php:2084`, `index.php:2130`, `index.php:2250-2252`, `index.php:2430-2481`) | `panels:active`, `panel:name:*` | No |
| Category/plan add/toggle/edit (`index.php:2603-2651`, `index.php:2800-2845`) | `category:active`, `category_date:active`, `category_limit:active` | No |
| Token refresh `UPDATE panels SET token` (`custom.php:242`) | `panel:name:*` row, `$token_tested` | No (same-request `RequestCache` still holds pre-refresh row; next request re-reads DB) |
| `bot_config.json` rewrites (`custom.php:57-61`, `App/Utils/config_tools.php:12-16`, `migrate.php:15`) | `botpanel_config` 300s | No |
| Lock-channel edits | `apcu:join:*` 60s | No, TTL only |
| Temp-flow cancel/finish | `botpanel_temp_*` | Yes, explicit deletes above |

Top fixes if staleness bites: add `apcu_delete('botpanel_config')` to config writers; reset global `RequestCache` or bypass it on admin writes; add `apcu_delete` for `botpanel_tables_exist` on migrations; scope `$token_tested` per panel name instead of global boolean.
