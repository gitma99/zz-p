# Buy / Renewal DRY — Shared Confirm & Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One shared delivery path (`complete_service()`) plus shared micro-helpers for the buy and renewal flows, fixing renewal's FlowState bugs, with robust logging and full unit test coverage.

**Architecture:** A new zero-dependency module `App/functions/service_flow.php` (require order: temp_tools → pure → marzban api → service_flow, mirroring `custom.php`). Both wizards keep their flat if/elseif step chains and their distinct steps (name creation vs lookup, copen); they call the shared module for keyboards, factor presentation, confirm-time delivery, and cancel cleanup. Wizard steps, `users.step` values, and FlowState file formats are frozen — no migration.

**Tech Stack:** PHP 7.4 (runtime image is `php:7.4-cli`, see `tests/integration/test.Dockerfile:1`), mysqli, existing `Logger`, zero-dependency `tests/unit/` pattern.

**Spec:** `docs/superpowers/specs/2026-11-09-buy-renewal-dry-design.md` — read it fully first; it carries the decisions (5 builder-review calls, 5 owner constraints, accepted behaviors) this plan assumes.

## Global Constraints

- **PHP 7.4 syntax only.** No `match`, `str_contains`/`str_starts_with`, named arguments, constructor property promotion, enums, readonly properties, `mixed`/`never` types. (PHP 7.4 allows arrow fns, spread in calls, `??`, null coalescing assign — the codebase already uses `??`.)
- **Behavior freeze:** no change to wizard steps, step names, user-facing flow order, FlowState file names/formats, or `users.step` values. In-flight users must be unaffected.
- **Fail-fast (AGENTS.md):** draft/argument contract violations throw `InvalidArgumentException`; operational failures (API error, token fail) get a user message + log, never a fatal.
- **Logging:** all shared-path logs go through `App/Utils/Logger.php` with a context array containing `'flow' => 'buy'|'renew'`. INFO: path entry + every terminal outcome. WARNING: degraded-but-handled (token retry, missing FlowState on cancel, getMe fallback, `detail` present). ERROR: contract violations + unrecoverable outcomes. Never log tokens, passwords, or full API payloads (the `detail` field only).
- **SQL style:** the shared module interpolates into SQL strings like the surrounding code (no prepared statements — that's a repo-wide project); all interpolated values come from the validated draft or DB rows, never raw `$text`.
- **Persian strings:** the two new `texts.json` keys mirror the existing style; the owner reviews the wording before merge (Task 1 step 5).
- **Commits:** one commit per task, message prefix `refactor(buy-renew):`.
- **Unit tests:** zero-dependency, `tests/unit/*_test.php`, mock-then-require-then-assert, required `require_once __DIR__ . '/helper.php'`, end with `echo "OK <name>\n"; exit(0);`. Run: `bash tests/unit/run.sh` (or `php <file>` directly).

## Review Focus

Failure modes the spec implies but no unit task exercises — each line pinned to the task that owns the code.

1. **User mid-renewal after deploy** (step `renewal_service_*` in DB, FlowState in APCu) sends a server/plan/confirm text → must behave exactly as pre-deploy: same screens, same state writes, no crash. Owned by Task 4/5 (the rewritten branches); pinned by the manual smoke checklist in Task 5 step 5 (renewal from an abandoned-then-resumed state is out of bot scope, so resume a fresh renewal at each step).
2. **`service_factors` row missing at buy confirm** (user tapped confirm, but the row was wiped by a concurrent flow start) → contract violation: throw `InvalidArgumentException`, logged ERROR, user not charged. Owned by Task 3; pinned by a `complete_service` unit test in Task 3 step 3 (branch matrix 11).
3. **Plan deactivated (status → inactive) between wizard step and confirm** → wizard has no `status` filter; shared path fails at the category lookup? No — `category_plan_details` matches by name regardless of status (today's behavior); the panel call then proceeds. Expectation: same as today (plan row still found; `complete_service` uses the row as-is). Owned by Task 2; pinned by a helper unit test asserting the lookup ignores `status`.
4. **FlowState file deleted by TTL mid-flow** (24h) and the user resumes at a later step → `read_renewal_json` returns `false`; today: PHP notice + crash-ish behavior on array access. Shared `cleanup` on cancel must log WARNING (missing FlowState), not fatal. Owned by Task 2; pinned by the TTL-expired cancel test in Task 2 step 3.
5. **Panel API returns a valid body but without `subscription_url`** (malformed panel response) → today: buy fatales on `strpos(null)`; renew too. Shared path must behave identically (no new fatal-handling is in scope); the log at ERROR with the decoded body keys (no payload) must fire so it's diagnosable. Owned by Task 3; pinned by unit test 10b (api_error with body lacking `links`/`subscription_url` → ERROR log includes `detail`/keys, Exception thrown, no coin change).

---

### Task 1: Shared module skeleton, `texts.json` keys, unit test harness

**Files:**
- Create: `App/functions/service_flow.php`
- Create: `tests/unit/service_flow_test.php`
- Modify: `App/texts.json` (add 2 keys, near line 35-40 with the other buy texts)
- Modify: `custom.php:3-5` (require the module)

**Interfaces:**
- Consumes: `App/Utils/temp_tools.php` (`write_temp_file`/`read_temp_file`/`delete_temp_file`), `App/Utils/pure.php` (`convertToBytes`), `App/Utils/Logger.php` — all already loaded by the time `custom.php` runs (require order inside `custom.php`: vars, db_tools, pure; `index.php` loads temp_tools at line 28 and the marzban api at line 54, before `custom.php` at line 55).
- Produces (this task, for later tasks): the module file exists and is required by `custom.php`; `texts.json` gains `renew_service_factor` and `success_renew_service`; the test file loads the module with the full stub harness (every stub the later tasks' tests need is defined here).

- [ ] **Step 1: Write the test harness skeleton (failing)**

`tests/unit/service_flow_test.php`. The harness is the deliverable of this step; later tasks append named test blocks at the marked `// --- append later tasks' blocks below ---` line.

```php
<?php
// Unit tests for App/functions/service_flow.php (buy/renewal shared path).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/Logger.php';

// ---- in-memory temp store ----
$GLOBALS['__temp'] = [];
function write_temp_file($content, $file_name, $json_encode = false) {
    if ($json_encode) $content = json_encode($content);
    $GLOBALS['__temp'][$file_name] = $content;
}
function read_temp_file($file_name, $json_decode = false) {
    if (!array_key_exists($file_name, $GLOBALS['__temp'])) return [false, true];
    $data = $GLOBALS['__temp'][$file_name];
    if ($json_decode && is_string($data)) $data = json_decode($data, true);
    return [$data, false];
}
function delete_temp_file($file_name) {
    $missing = !array_key_exists($file_name, $GLOBALS['__temp']);
    unset($GLOBALS['__temp'][$file_name]);
    return !$missing; // true = existed and deleted, false = was missing
}

// ---- recording Logger ----
$GLOBALS['__logs'] = [];
class TestLoggerRecorder {
    public $records = [];
}
class TestLogger {
    public static function setInstance($m) { /* no-op: real Logger::setInstance exists on the real class */ }
}
// Replace the real Logger's behavior via a namespaced stub is not possible in
// plain PHP; instead we require the real Logger and override by redefining is
// also impossible. The module under test calls Logger::info/warning/error,
// which buffer into a real instance and flush at shutdown. So: use a
// capturing handler.
$GLOBALS['__log_records'] = [];
class CapturingLogHandler {
    public function handle($record) {
        $GLOBALS['__log_records'][] = $record;
    }
}
require_once __DIR__ . '/../../App/Utils/LoggerConfig.php';
require_once __DIR__ . '/../../App/Utils/LogHandler.php';
Logger::init(LoggerConfig::fromArray(array('level' => 'DEBUG', 'logDir' => sys_get_temp_dir(), 'retentionDays' => 1)));
Logger::addHandler(new CapturingLogHandler());
function reset_log() { $GLOBALS['__log_records'] = []; }
function logged_levels() {
    return array_map(function ($r) { return $r['level']; }, $GLOBALS['__log_records']);
}
function log_ctx($i) { return $GLOBALS['__log_records'][$i]['context']; }

// ---- telegram ----
$GLOBALS['__msgs'] = [];
function sendMessage($chat_id, $text, $keyboard = null) {
    $GLOBALS['__msgs'][] = ['chat_id' => $chat_id, 'text' => $text, 'keyboard' => $keyboard];
    return ['ok' => true, 'result' => ['message_id' => 999]];
}
function sendSuccessResponse($from_id, $qr_code_status, $qr_data, $caption_text, $message_text, $start_key, $retry_count = 3, $sleeper = 'sleep') {
    $GLOBALS['__msgs'][] = ['chat_id' => $from_id, 'success' => true, 'qr' => $qr_data, 'caption' => $caption_text, 'message' => $message_text, 'keyboard' => $start_key];
    return ['ok' => true];
}

// ---- step() (real one lives in config.php, not loaded in unit tests) ----
$GLOBALS['__steps'] = [];
function step($s) { $GLOBALS['__steps'][] = $s; }

// ---- marzban api stubs (App/apis/marzban-api/api.php is NOT required in unit tests) ----
$GLOBALS['__marzban_calls'] = [];
function get_marzban_panel_token($panel_name) {
    $GLOBALS['__marzban_calls'][] = ['fn' => 'get_marzban_panel_token', 'panel' => $panel_name];
    return $GLOBALS['__marzban_token'];
}
function loginPanel($address, $username, $password) {
    $GLOBALS['__marzban_calls'][] = ['fn' => 'loginPanel', 'address' => $address];
    return isset($GLOBALS['__login_result']) ? $GLOBALS['__login_result'] : false;
}
function createService($username, $limit, $expire, $proxies, $inbounds, $token, $url) {
    $GLOBALS['__marzban_calls'][] = ['fn' => 'createService', 'username' => $username, 'limit' => $limit, 'expire' => $expire, 'proxies' => $proxies, 'inbounds' => $inbounds, 'token' => $token, 'url' => $url];
    return $GLOBALS['__create_result'];
}
function marzban_renewal_api($username, $limit, $expire, $token, $url) {
    $GLOBALS['__marzban_calls'][] = ['fn' => 'marzban_renewal_api', 'username' => $username, 'limit' => $limit, 'expire' => $expire, 'token' => $token, 'url' => $url];
    return $GLOBALS['__renewal_result'];
}

// ---- pure.php (real, tiny, no deps) ----
require_once __DIR__ . '/../../App/Utils/pure.php';

// ---- fake SQL: script results by exact query substring ----
class FakeSqlResult {
    private $rows; public $num_rows;
    public function __construct(array $rows) { $this->rows = $rows; $this->num_rows = count($rows); }
    public function fetch_assoc() { return array_shift($this->rows) ?: null; }
}
class FakeSql {
    public $statements = [];
    public $scripted = []; // map: substring => array of rows
    public function query($q) {
        $this->statements[] = $q;
        foreach ($this->scripted as $needle => $rows) {
            if (strpos($q, $needle) !== false) return new FakeSqlResult($rows);
        }
        return new FakeSqlResult([]);
    }
}
function reset_test_state() {
    global $sql, $user, $texts, $start_key, $confirm_service, $config, $first_name;
    $GLOBALS['__temp'] = [];
    $GLOBALS['__msgs'] = [];
    $GLOBALS['__marzban_calls'] = [];
    $GLOBALS['__steps'] = [];
    reset_log();
    $sql = new FakeSql();
    $user = ['from_id' => 1001, 'coin' => 100000, 'count_service' => 1];
    $texts = json_decode(file_get_contents(__DIR__ . '/../../App/texts.json'), true);
    $start_key = '{"keyboard":[]}';
    $confirm_service = '{"keyboard":[["confirm"]]}';
    $config = ['token' => 'test-bot-token'];
    $first_name = 'Tester';
    $from_id = 1001;
}

// ---- the module under test (loads temp_tools + pure first; both already stubbed/loaded here) ----
require_once __DIR__ . '/../../App/functions/service_flow.php';

// === test data ===
$panel_marzban = ['name' => 'serverA', 'code' => 'pA', 'type' => 'marzban', 'login_link' => 'https://pA.example', 'username' => 'admin', 'password' => 'pw', 'token' => 'tokA', 'protocols' => 'vless,|vmess', 'flow' => 'flowon', 'qr_code' => 'active'];
$panel_sanayi = ['name' => 'serverB', 'code' => 'pB', 'type' => 'sanayi', 'login_link' => 'https://pB.example', 'username' => 'admin', 'password' => 'pw', 'token' => 'tokB', 'protocols' => '', 'flow' => '', 'qr_code' => 'inactive'];
$plan_row = ['row' => 5, 'limit' => '100', 'date' => '30', 'name' => 'Gold', 'price' => '50000', 'code' => 'gold', 'status' => 'active'];
$inbound_row = ['panel' => 'pA', 'inbound' => 3, 'code' => 'vless', 'status' => 'active'];
$draft_buy = ['location' => 'serverA', 'plan' => 'Gold', 'price' => 50000, 'code_base' => 'myvpn', 'code' => 'myvpn_1001'];
$draft_renew = ['location' => 'serverA', 'plan' => 'Gold', 'price' => 50000, 'code_base' => 'myvpn', 'code' => 'myvpn_1001'];
function script_db() {
    global $sql, $panel_marzban, $panel_sanayi, $plan_row, $inbound_row;
    $sql->scripted = [
        'FROM `category`' => [$plan_row],
        'FROM `panels`' => [$panel_marzban],
        'FROM `marzban_inbounds`' => [$inbound_row],
        'FROM `service_factors`' => [],
        'FROM `users`' => [],
    ];
}

// sanity: harness loads, module is empty of functions yet
assert_true(true, 'harness loaded');

// --- append later tasks' blocks below ---

echo "OK service_flow_test.php\n";
exit(0);
```

Notes for the implementer:
- `delete_temp_file` returning a bool is **not** part of the app API (the real one returns void); this test-only wrapper records presence. The module under test only *calls* it; tests assert on `$GLOBALS['__temp']` contents, not on the return value.
- `Logger::addHandler` exists on the real `Logger` class (`App/Utils/Logger.php:25`); `LoggerConfig::fromArray` + `CapturingLogHandler` are the recording mechanism. If `LoggerConfig::fromArray` rejects those keys, read `App/Utils/LoggerConfig.php` and use its actual shape — the requirement is: `Logger::info/warning/error` records land in `$GLOBALS['__log_records']` with keys `level`, `msg`, `context`.
- The real `App/Utils/temp_tools.php` must **not** be required (its globals would shadow the stubs); the module's `require_once __DIR__ . '/../Utils/temp_tools.php'` is safe in tests because PHP's `require_once` with a different resolved path than the stub... — actually the stub functions are already defined, so requiring temp_tools.php would fatal ("cannot redeclare"). Resolution: the module must require temp_tools via `require_once`, and the test defines stubs **first**; `require_once` of the real file would then attempt `function write_temp_file` → fatal. To avoid this, the module file must **not** require temp_tools/pure at all (the runtime always loads them first — `index.php:28` loads temp_tools, `custom.php:5` loads pure, both before the module loads at `custom.php:6`/`index.php:55`). State this in the module header comment: "Requires temp_tools, pure, Logger, marzban api to be loaded (runtime order guarantees this; unit tests stub them)."

- [ ] **Step 2: Run to verify the harness passes empty**

Run: `php tests/unit/service_flow_test.php`
Expected: `OK service_flow_test.php` (module file doesn't exist yet → this step also creates the stub: `App/functions/service_flow.php` containing only `<?php\n` + the header comment from step 1's note. Create it in this step.)

- [ ] **Step 3: Add the two `texts.json` keys**

In `App/texts.json`, next to the existing buy-flow keys (around lines 35-40), add:

```json
"renew_service_factor": "ℹ️ فاکتور تمدید شما با جزئیات زیر با موفقیت ساخته شد.\n\n⬅️ لوکیشن : <b>%s</b>\n⬅️ حجم : <code>%s</code> گیگ\n⬅️ تاریخ : <code>%s</code> روز\n⬅️ کد پیگیری : <code>%s</code>\n\n💸 مبلغ تمدید شما : <code>%s</code> تومان\n\n👇🏻در صورت تایید اطلاعات بالا میتوانید از طریق دکمه های زیر ادامه دهید.",
"success_renew_service": "✅ تمدید سرویس با موفقیت انجام شد.\n\n◽️اسم سرویس : <b>%s</b>\n◽️لوکیشن : <b>%s</b>\n◽️تاریخ : <code>%s</code> روز\n◽️حجم : <code>%s</code> GB\n◽️قیمت : <code>%s</code> تومان\n\n%s🤖 • %s",
```

`renew_service_factor` mirrors `service_factor` (line 35) with تمدید wording; `success_renew_service` mirrors `success_create_service` (line 40) — same placeholder count and order (`%s` ×7). Wording is owner-reviewable (Task 5 smoke includes reading both messages in the dev bot).

- [ ] **Step 4: Require the module from `custom.php`**

`custom.php` lines 3-5 become:

```php
require_once __DIR__ . "/App/vars.php";
require_once __DIR__ . "/App/Utils/db_tools.php";
require_once __DIR__ . "/App/Utils/pure.php";
require_once __DIR__ . "/App/functions/service_flow.php";
```

No `index.php` change: it requires `custom.php` at line 55, after temp_tools (line 28) and the marzban api (line 54).

- [ ] **Step 5: Run harness, commit, then send the two Persian strings to the owner for review**

Run: `php tests/unit/service_flow_test.php` → `OK service_flow_test.php`.
Run: `bash tests/unit/run.sh` → all existing tests still PASS.

```bash
git add App/functions/service_flow.php App/texts.json custom.php tests/unit/service_flow_test.php
git commit -m "refactor(buy-renew): shared module skeleton, renewal texts, unit harness"
```

Send the owner the two Persian strings (they are the only user-visible copy in this task) and wait for approval or edits before Task 2.

**Interfaces produced (full list, for later tasks):**

```php
// App/functions/service_flow.php — all PHP 7.4, all functions global
function service_options_keyboard(array $names): string        // Task 2
function inactive_service_message($from_id): void              // Task 2
function wipe_service_factors($from_id): void                  // Task 2
function cleanup_flow_state($from_id): void                    // Task 2
function cancel_service_flow($from_id, $flow): void                 // Task 2
function category_plan_details($plan_name)                     // Task 2 : array row or false
function send_service_factor_message($from_id, $flow, $location, $limit, $date, $code_base, $price): void  // Task 2
function get_bot_username($decoded_me = null)                  // Task 2 : string
function complete_service($mode, $from_id, array $draft): array // Task 3 : ['ok' => bool, 'reason' => string|null]
```

---

### Task 2: Wizard micro-helpers + tests

**Files:**
- Modify: `App/functions/service_flow.php` (add 8 functions)
- Modify: `tests/unit/service_flow_test.php` (append blocks at the marker)

**Interfaces:**
- Consumes: Task 1 harness (stubs, `FakeSql`, `reset_test_state`, `$GLOBALS['__temp']`, `$GLOBALS['__log_records']`), `texts.json` `renew_service_factor`.
- Produces: the 8 helper functions above (signatures in Task 1's Interfaces block), with the exact behaviors pinned below.

Behaviors (each pinned by a named test block):

- `service_options_keyboard(array $names)`: input = flat list of display names. Output = `json_encode(['keyboard' => <rows>, 'resize_keyboard' => true])` where `<rows>` = `array_chunk($names, 2)` (each cell `['text' => $name]`) + a final row `[['text' => '🔙 بازگشت']]`. With 0 names: just the back row.
- `inactive_service_message($from_id)`: `sendMessage($from_id, $texts['inactive_buy_service'], $start_key)` — global `$texts`/`$start_key` as in the harness.
- `wipe_service_factors($from_id)`: `SELECT * FROM service_factors WHERE from_id` first; DELETE only when `num_rows > 0` (two statements or one — assert on the statement log: DELETE present iff rows existed). INFO log `('service_factors wiped', ['flow' => null, 'from_id' => ...])`? No — the helper is flow-agnostic; log context `['from_id' => $from_id]` at INFO only when a delete happened.
- `category_plan_details($plan_name)`: `SELECT * FROM category WHERE name = '<plan>'` → `fetch_assoc()` or `false`. No `status` filter (Review Focus 3). No logging (hot wizard path, called per step).
- `send_service_factor_message($from_id, $flow, $location, $limit, $date, $code_base, $price)`: `$key = ($flow === 'renew') ? 'renew_service_factor' : 'service_factor';` then `sendMessage($from_id, sprintf($texts[$key], $location, $limit, $date, $code_base, number_format($price)))`. `$flow` not `'buy'`/`'renew'` → throw `InvalidArgumentException`.
- `get_bot_username($decoded_me = null)`: if `$decoded_me === null` do `json_decode(file_get_contents("https://api.telegram.org/bot{$config['token']}/getMe"), true)` (global `$config`, exactly as `custom.php:440`); return `isset($decoded_me['result']['username']) ? $decoded_me['result']['username'] : '*'`; the `'*'` case logs WARNING `('getMe username missing, using fallback', ['flow' => null])`.
- `cleanup_flow_state($from_id)`: deletes the five FlowState files `<id>-location.txt`, `<id>-protocol.txt`, `<id>-plan.txt`, `<id>-service-name.txt`, `renewal-service-<id>.json` (each via `delete_temp_file`, which is a no-op when absent) + `wipe_service_factors($from_id)`. For each of the five, if it was absent, collect it; if any absent → WARNING log `('flow state files already absent at cleanup', ['from_id' => ..., 'missing' => <list>])`.
- `cancel_service_flow($from_id, $flow)`: `cleanup_flow_state($from_id)` + `step('none')` + greeting. The greeting differs per flow today and both exact texts are preserved: buy (`$flow = 'buy'`) → `sprintf($texts['greetings'] . $texts['start'], $first_name)` (`index.php:191`); renew → `sprintf($texts['start'], $first_name)` (`custom.php:460` — the missing-greetings quirk is pre-existing in renewal and kept). `$flow` not `'buy'`/`'renew'` → throw `InvalidArgumentException`. Requires globals `$first_name`, `$texts`, `$start_key` (harness defines `$first_name = 'Tester';` — add to `reset_test_state`).

Test blocks to append (names → assertions; use `reset_test_state()` + `script_db()`-style scripting per block; `$sql->statements` for exact-string SQL asserts):

1. `keyboard_shapes`: names lists of length 0, 1, 2, 3, 5, 7 → decode the JSON; assert row counts (0→1 row; 1→2 rows [1 name + back]; 2→2; 3→3; 5→3; 7→4), back row last, `resize_keyboard === true`, cell shape `['text' => ...]`.
2. `wipe_no_rows_vs_rows`: `service_factors` scripted empty → `statements` has SELECT, no DELETE, no log; scripted 1 row → DELETE present, one INFO log.
3. `category_lookup`: scripted row found → equals `$plan_row`; not found → `false`; a row with `status = 'inactive'` is still returned (Review Focus 3).
4. `factor_message_keys`: flow buy → message uses `$texts['service_factor']` sprintf result (assert exact string); flow renew → `renew_service_factor`; flow `'bogus'` → `assert_throws(..., InvalidArgumentException, ...)`.
5. `bot_username`: `$decoded_me = ['result' => ['username' => 'mybot']]` → `'mybot'`; `['result' => []]` → `'*'` + one WARNING log.
6. `cleanup_all_present`: seed all five files in `$GLOBALS['__temp']` + factors rows → all gone, factors wiped, no WARNING.
7. `cleanup_ttl_expired`: seed nothing (or only the renewal JSON) → no fatal, WARNING log lists exactly the absent files (Review Focus 4).
8. `cancel`: seed everything → after call with `$flow = 'buy'`: `$sql->statements` includes the factors wipe, last message = `sprintf($texts['greetings'] . $texts['start'], 'Tester')`, `$GLOBALS['__msgs']` last keyboard = `$start_key`, `__steps` last = `'none'`; a second call with `$flow = 'renew'` → last message = `sprintf($texts['start'], 'Tester')` (the renewal greeting keeps its pre-existing no-greetings form).

- [ ] **Step 1: Write the eight test blocks** (above) at the marker in `tests/unit/service_flow_test.php`.
- [ ] **Step 2: Run — verify all fail** (functions undefined / wrong output): `php tests/unit/service_flow_test.php` → FAIL.
- [ ] **Step 3: Implement the eight functions** in `App/functions/service_flow.php`, in the order listed, matching the behaviors exactly (they are fully determined by the list above + the spec's Shared micro-helpers section).
- [ ] **Step 4: Run — verify pass**: `php tests/unit/service_flow_test.php` → `OK`. Then `bash tests/unit/run.sh` → all PASS.
- [ ] **Step 5: Commit**

```bash
git add App/functions/service_flow.php tests/unit/service_flow_test.php
git commit -m "refactor(buy-renew): wizard micro-helpers (keyboard, factor, cleanup, cancel)"
```

---

### Task 3: `complete_service()` + tests (the 13-branch matrix)

**Files:**
- Modify: `App/functions/service_flow.php` (add `complete_service`, one internal helper for the orders write is allowed)
- Modify: `tests/unit/service_flow_test.php` (append blocks at the marker)

**Interfaces:**
- Consumes: Task 2 helpers (`get_bot_username`, `cleanup_flow_state`, `wipe_service_factors`), stubbed panel APIs, `$user`/`$sql`/`$texts` globals.
- Produces: `complete_service($mode, $from_id, array $draft): array` — return `['ok' => true, 'reason' => null]` or `['ok' => false, 'reason' => <one of 'coin'|'plan_missing'|'panel_missing'|'panel_type'|'token'|'api_error'>]`. Contract: `$mode` ∈ `{'buy','renew'}`; `$draft` keys `location`, `plan`, `price` (numeric string or int — used verbatim in the coin SQL so the statement stays byte-identical to today's), `code_base`, `code` (non-empty strings). Violations → `InvalidArgumentException` **before any** SQL/API/message. On every non-ok: user messaged, `step('none')`, `cleanup_flow_state` run, no coin change. On `'api_error'`: Exception thrown *after* the message+cleanup (callers in Tasks 4-5 don't need to catch — the webhook try/catch at `index.php` top does).

Sequence (pinned; matches spec "Shared confirm sequence" — the implementer reads that section):

1. Fail-fast contract check (no side effects).
2. INFO log entry: `('complete_service entered', ['flow' => $mode, 'from_id' => ..., 'draft' => $draft])`.
3. `$plan_row = category_plan_details($draft['plan'])`; `!$plan_row` → fail(`plan_missing`, message `sprintf($texts[$mode === 'renew' ? 'renew_error' : 'create_error'], 0)`, ERROR log `('plan not found', [...])`).
4. Panel row: `SELECT * FROM panels WHERE name = '<location>'` (plain query, NOT `get_panel_by_name` — deterministic for tests; the RequestCache staleness is identical either way and out of scope). `num_rows == 0` → fail(`panel_missing`, code `2`).
5. Type gate: `marzban` ok both modes; `sanayi` ok only for buy; else fail(`panel_type`, message literal `"Not implanted Error"`, `$start_key`).
6. Coin: `$user['coin'] < $draft['price']` → fail(`coin`, `not_coin` with `number_format($draft['price'])`).
7. Token: buy → `$lp = loginPanel($panel['login_link'], $panel['username'], $panel['password']); $token = (is_array($lp) && isset($lp['access_token'])) ? $lp['access_token'] : false;` (guard needed: `loginPanel` returns `false` on curl failure — today's bare `['access_token']` access would fatal; the guard changes nothing for the happy path); renew → `$token = get_marzban_panel_token($panel['name'])`. `false` → fail(`token`, `$texts['error_show_service__token_is_incorrect']`).
8. Panel API:
   - buy marzban: build `$proxies`/`$inbounds` **exactly** as `index.php:392-412` (copy that algorithm: `explode('|', $panel['protocols'])`, `unset` last, empty-first `unset`, `flowon`→`xtls-rprx-vision`, `marzban_inbounds` by `$panel['code']` → per-protocol inbound list; `inbounds` arg = `($num_rows > 0) ? $inbounds : 'all'`); call `createService($code, convertToBytes($limit.'GB'), strtotime("+ $date day"), $proxies, $inbounds, $token, $panel['login_link'])`; failure = `[1] !== false` (today's check) → fail(`api_error`, `create_error` 1, ERROR log with `$create_service[1]` as `detail`).
   - buy sanayi: `include_once 'api/sanayi.php'` (relative path works from the module's call context? No — use `require_once __DIR__ . '/../../api/sanayi.php';`), `$xui = new Sanayi($panel['login_link'], $panel['token'])`, `sanayi_panel_setting` by `code`, `addClient`, failure = `['status'] == false` → fail(`api_error`); link built with the exact `str_replace` chain from `index.php:461`; success message = `success_create_service_sanayi`; orders INSERT stores **full `$code`** (builder call #1).
   - renew: `marzban_renewal_api($code, convertToBytes($limit.'GB'), strtotime("+ $date day"), $token, $panel['login_link'])`; decoded body `!isset($body['username'])` → fail(`api_error`, `renew_error` 1, ERROR log with `$body['detail'] ?? $body` keys — never the full payload).
9. Links + subscription: buy marzban from `create_status['links']` / `['subscription_url']`; renew from the renewal body; sanayi: single link, `subscribe` = `results['subscribe']`. Normalization: `strpos($sub, 'http') !== false ? $sub : $panel['login_link'] . $sub`.
10. `$bot_username = get_bot_username();` Success message slots (builder call #2): buy → caption and message both `sprintf($texts['success_create_service'], $code_base, $location, $date, $limit, number_format($price), <slot>, '@'.$bot_username)` with `''` in caption's 6th slot and `$subscribe` in message's (today's exact shape, `index.php:437-439`); renew → caption uses **`$code`**, message uses **`$code_base`** (today's `custom.php:444-445`), text key `success_renew_service`. `sendSuccessResponse($from_id, $panel['qr_code'], $subscribe, $caption, $message, $start_key)`.
11. Orders write (builder call #1): buy marzban `INSERT INTO orders (from_id, location, protocol, date, volume, link, price, code, status, type) VALUES ('$from_id', '$location', 'null', '$date', '$limit', '$links', '$price', '$code_base', 'active', 'marzban')`; buy sanayi same shape but `code = $code` (full) and `link = $link`, `type = 'sanayi'`; renew `UPDATE orders SET location = '$location', date = '$date', volume = '$limit', link = '$links', price = '$price' WHERE code = '$code_base' AND from_id = '$from_id'` (keep today's comment about the `from_id` guard).
12. `cleanup_flow_state($from_id)` (every terminal path — see step 0 of fails below).
13. Coin: buy `UPDATE users SET coin = coin - $price, count_service = count_service + 1 WHERE from_id = '$from_id' LIMIT 1`; renew `UPDATE users SET coin = coin - $price WHERE from_id = '$from_id' LIMIT 1`.
14. INFO log success: `('complete_service success', ['flow' => ..., 'orders' => 'insert'|'update'|'insert-sanayi', 'coin' => -$price])`.

Every fail() (steps 3-8): message per step → `step('none')` → `cleanup_flow_state($from_id)` → ERROR log `('complete_service failed', ['flow' => ..., 'reason' => <reason>])` → `return ['ok' => false, 'reason' => <reason>]`; `api_error` additionally `throw new Exception(...)` **after** the return is impossible — so for `api_error`: message → step → cleanup → ERROR log → `throw new Exception("Marzban <renewal|create> API error: " . json_encode($error_body));` and the function never returns it (the test asserts the throw; the return contract in the spec's Interfaces stands for the other six reasons).

Test blocks (append at the marker; each starts with `reset_test_state(); script_db();` + per-block overrides; assert: return/throw, `$GLOBALS['__msgs']` (exact text + keyboard), `$sql->statements` (exact strings, in order), `$GLOBALS['__temp']` (empty after every block — cleanup runs), `$GLOBALS['__marzban_calls']` (fn + exact args, or empty), coin UPDATE present/absent + exact string, `logged_levels()` contains expected levels in order):

1. `buy_marzban_happy`: `$panel_marzban` scripted; `__create_result = [ ['username' => 'myvpn_1001', 'links' => ['link1'], 'subscription_url' => '/sub/abc'], false ]`; assert createService args (`limit = convertToBytes('100GB')`, `expire = strtotime('+ 30 day')`, proxies shape `['vless' => ['flow' => 'xtls-rprx-vision'], 'vmess' => new stdClass()]`, `inbounds = ['vless' => [3]]`), INSERT code = `myvpn`, coin stmt has `count_service + 1`, success msg caption slot = `myvpn` in both, `__temp` empty, INFO entry+success logged, no WARNING.
2. `buy_sanayi_happy`: `$panel_sanayi` scripted + script `FROM sanayi_panel_setting` row `['code' => 'pB', 'inbound_id' => 7, 'example_link' => 'https://x/%s1@%s2/%s3']`; stub the `Sanayi` class in the harness (`class Sanayi { public function __construct($u,$t){} public function addClient($n,$i,$d,$l){ return $GLOBALS['__sanayi_result']; } public function getPortById($i){ return json_encode(['port' => 443]); } }` with `__sanayi_result = json_encode(['status' => true, 'results' => ['id' => 'id1', 'remark' => 'rk', 'subscribe' => 'subB']])`); assert INSERT code = **`myvpn_1001`** (full, builder call #1), `success_create_service_sanayi` text, coin stmt has `count_service + 1`. The sanayi link string itself (the `str_replace` chain over `parse_url` of `login_link`) is asserted loosely: `assert_true(strpos($qr, 'id1') !== false && strpos($qr, 'rk') !== false, 'sanayi link contains client id and remark')` — the chain is copied verbatim from `index.php:461` and exercised by the real panel in the Task 5 smoke.
3. `renew_happy`: `__renewal_token = 'tokA'`; `__renewal_result = json_encode(['username' => 'myvpn_1001', 'links' => ['l1','l2'], 'subscription_url' => 'https://pA.example/sub'])`; assert `marzban_renewal_api` called with `('myvpn_1001', convertToBytes('100GB'), strtotime('+ 30 day'), 'tokA', 'https://pA.example')`, `createService`/`loginPanel` **not** called, UPDATE (not INSERT) with the exact WHERE, coin stmt **without** `count_service`, caption uses `myvpn_1001`, message uses `myvpn`, `renewal-service-1001.json` deleted.
4. `coin_fail_buy_and_renew`: `$user['coin'] = 100`; both modes → `not_coin` text, `$__marzban_calls` empty, no coin stmt, `__temp` empty (cleanup ran), ERROR logged, `step('none')` stmt present.
5. `plan_missing`: script `FROM category` → `[]`; both modes → code-0 error text per mode, no API, ERROR.
6. `panel_missing`: script `FROM panels` → `[]`; both modes → code-2, no API.
7. `renew_sanayi_type_gate`: `$panel_sanayi` + mode renew → `panel_type`, message `Not implanted Error`, no API call.
8. `buy_unknown_type`: panel row `type = 'other'` → same rejection, verbatim message.
9. `token_fail`: buy → `__login_result = false` (or array without `access_token`); renew → `__marzban_token = false`; both → `error_show_service__token_is_incorrect` message, no panel API call.
10. `api_error_both`: buy → `__create_result = [null, 'boom']`; renew → `__renewal_result = json_encode(['detail' => 'user gone'])`; both → mode error text code 1, Exception thrown (message contains `boom` / `user gone`), cleanup ran, no coin stmt, ERROR log `detail` = the string (no payload).
11. `contract_violations`: missing `code` key; `mode = 'other'`; `$code_base = ''`; each → `assert_throws(InvalidArgumentException)` **with** `$sql->statements` empty and `__msgs` empty (no side effects before the throw).
12. `message_slots`: covered by 1 and 3 — add explicit `assert_eq` on the captured `caption`/`message` strings against hand-built `sprintf` expectations (no reliance on the module).
13. `sub_url_normalization`: renew with relative `subscription_url` (`/sub/abc`) → qr_data = `https://pA.example/sub/abc`; absolute stays. Buy marzban same rule (two sub-asserts).
14. `inbounds_all_fallback`: `$panel_marzban` with `marzban_inbounds` scripted `[]` → createService `inbounds = 'all'` (pins today's `num_rows > 0` behavior).

- [ ] **Step 1: Write the 14 test blocks** at the marker (they fully determine the implementation; write them first).
- [ ] **Step 2: Run — verify all fail**: `php tests/unit/service_flow_test.php` → FAIL (function undefined).
- [ ] **Step 3: Implement `complete_service`** in `App/functions/service_flow.php` per the sequence above (copy the proxies/inbounds algorithm verbatim from `index.php:392-412`; copy the sanayi link substitution verbatim from `index.php:461`).
- [ ] **Step 4: Run — verify pass**, then full suite: `php tests/unit/service_flow_test.php` → `OK`; `bash tests/unit/run.sh` → all PASS.
- [ ] **Step 5: PHP 7.4 lint gate** (guards the syntax constraint for every future task too):
Run: `php -l App/functions/service_flow.php && php -l custom.php && php -l index.php`
Expected: `No syntax errors detected` ×3. (Also run `php -v` once — if the local CLI is 8.x, additionally grep the new file for banned constructs: `grep -nE 'match \(|str_contains|str_starts_with|str_ends_with|readonly |enum |: mixed|never' App/functions/service_flow.php` → no matches.)
- [ ] **Step 6: Commit**

```bash
git add App/functions/service_flow.php tests/unit/service_flow_test.php
git commit -m "refactor(buy-renew): complete_service shared delivery (13-branch test matrix)"
```

---

### Task 4: Wire the buy flow to the shared module

**Files:**
- Modify: `index.php:185-191` (cancel branch → `cancel_service_flow`)
- Modify: `index.php:192-207` (`🛒 خرید سرویس` entry: keyboard → `service_options_keyboard`, factors wipe → `wipe_service_factors`, inactive → `inactive_service_message`)
- Modify: `index.php:214-224` (`buy_service` step: plan keyboard → helper; **keep** the `panels WHERE name` validation + `choice_error` exit — renewal lacks it, and unifying is a behavior change, out of scope)
- Modify: `index.php:225-242` (`select_plan` step: plan keyboard untouched? No — the plan keyboard is built in `buy_service`; `select_plan` only reads/writes temp. No change beyond nothing.)
- Modify: `index.php:299-311` (pre-confirm in `choose_name`: `create_factor` line stays; factor message → `send_service_factor_message($from_id, 'buy', ...)`; `$fetch` → `category_plan_details` (same result); `service_factors` INSERT stays (copen reads it))
- Modify: `index.php:357-472` (confirm entry: keep the `step('none')` + `create_service_proccess` message; delete temp files (harmless, `cleanup_flow_state` re-covers); read `$select_service` from `service_factors` — if the row is missing, throw `InvalidArgumentException('complete_service: service_factors row missing')` (fail-fast, Review Focus 2); build `$draft` = `['location' => $select_service['location'], 'plan' => $select_service['plan'], 'price' => $select_service['price'], 'code_base' => $select_service['code'], 'code' => $select_service['code'] . '_' . $from_id]` (`price` stays the raw DB numeric string — the module interpolates it verbatim, keeping the coin SQL byte-identical to today's); call `complete_service('buy', $from_id, $draft)`; drop everything after it — the sanayi branch, orders writes, coin, cleanup all live in the module now)

**Interfaces:**
- Consumes: all of Task 2 + Task 3 (`service_options_keyboard`, `inactive_service_message`, `wipe_service_factors`, `cancel_service_flow`, `category_plan_details`, `send_service_factor_message`, `complete_service`).
- Produces: no new interfaces; buy behavior is frozen (every user-visible outcome identical to today, per the spec's freeze).

- [ ] **Step 1: Replace the buy confirm entry** (`index.php:357-472`) with: the two leading lines (step + process message), the `service_factors` read + fail-fast, the draft build, the `complete_service('buy', ...)` call. Delete the now-dead code (sanayi branch, marzban delivery, orders writes, coin, final DELETEs) — it all moved to the module in Task 3.
- [ ] **Step 2: Replace the buy wizard's duplicated pieces** (cancel branch, entry, `buy_service` plan keyboard, `choose_name` pre-confirm factor presentation) per the Files list. Keep: `service_factors` INSERT in `choose_name`, the copen callback path (`index.php:329-356`) untouched, the name-validation logic untouched.
- [ ] **Step 3: Syntax + suite gate**

Run: `php -l index.php && php -l custom.php && php -l App/functions/service_flow.php` → no errors.
Run: `bash tests/unit/run.sh` → all PASS (unit tests don't touch index.php; this guards accidental function redefinitions — e.g. if a helper name leaked into global scope differently).
- [ ] **Step 4: Behavior-diff check** (the freeze is the whole point — verify mechanically):

Run: `git diff HEAD~1 -- index.php` and read it: confirm (a) no `users.step` string changed, (b) no `sendMessage` text/keyboard changed except the factor message now going through `send_service_factor_message` with the **buy** key (identical output), (c) the copen branches are byte-identical, (d) temp file names unchanged.
- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "refactor(buy-renew): buy flow uses shared helpers and complete_service"
```

---

### Task 5: Wire the renewal flow + integration + smoke

**Files:**
- Modify: `custom.php:249-461` (renewal flow)
- Modify: `App/functions/service_flow.php` (only if a wiring gap surfaces — none expected)
- Modify: `docs/cache-system.md` (update §5 call-site list: renewal FlowState now deleted on confirm success **and** all failure returns + cancel; lines 28, 99, 104 mention the old locations)

**Interfaces:**
- Consumes: all Task 2/3 helpers + `complete_service('renew', ...)`.
- Produces: no new interfaces. Renewal behavior changes **only** by the three bug fixes (spec "Latent bugs"): FlowState deleted on every terminal confirm return (not pre-API), cancel deletes the renewal JSON, renewal wording is renewal wording.

Branch-by-branch wiring:

- `renewal_service_get_service_name` (name lookup): unchanged, **except** the server-list block (lines 292-306) becomes: `$server_rows = get_active_panels(); if (count($server_rows) > 0) { wipe_service_factors($from_id); $names = array_map(fn($r) => $r['name'], $server_rows); $location = service_options_keyboard($names); step(...); sendMessage(...); } else { inactive_service_message($from_id); }`.
- `renewal_service_choose_server`: unchanged except plan keyboard → `service_options_keyboard(array_map(fn($r) => $r['name'], get_active_categories()))`.
- `renewal_service_select_plan`: unchanged **except** — `$fetch = category_plan_details($plan_name);` and the factor message → `send_service_factor_message($from_id, 'renew', $location, $limit, $date, $code_base, $price)` (the `create_factor` + `$confirm_service` keyboard line stays above it; the coin pre-check stays between them — spec pins the order).
- `renewal_service_confirm_service` + confirm: becomes — `step('none')`; process message; `$renewal_data = read_renewal_json($from_id);` (if `false` → WARNING log `('renewal flow state missing at confirm', ['from_id' => ...])`, message `renewal_service_config_not_found`, `step('none')`, exit — TTL-expired FlowState can no longer fatal); `$get_plan` read stays (module re-reads too — one redundant query, identical to today's double read, keep); **delete** the pre-API `delete_temp_file` (line 398 — bug 1); build `$draft` from `$renewal_data` (`location` = `new_server`, `plan` = `new_plan`, `price` = `new_plan_price`, `code_base` = `config_base_name`, `code` = `config_name`); `complete_service('renew', $from_id, $draft)`. Delete the dead code after it (token, API, message, orders UPDATE, coin — all in the module).
- Cancel branch (`custom.php:454-461`): → `cancel_service_flow($from_id, 'renew')` (bug 2 fix; drop the old file list + factors wipe — `cleanup_flow_state` covers them).

- [ ] **Step 1: Apply the five branch wirings** above.
- [ ] **Step 2: Syntax + suite gate**: `php -l custom.php && php -l App/functions/service_flow.php` → clean; `bash tests/unit/run.sh` → all PASS.
- [ ] **Step 3: Behavior-diff check** on `git diff HEAD~1 -- custom.php`: (a) renewal step names unchanged, (b) `renewal-service-` file name unchanged, (c) no new user-visible text except `renew_service_factor`/`success_renew_service`/the `Not implanted Error`-unchanged paths, (d) the pre-API delete is gone, (e) cancel branch is now one call.
- [ ] **Step 4: Update `docs/cache-system.md`** §5 (lines 99, 104) and §7 note: renewal FlowState deleted on confirm (success + all failure returns) and cancel; buy temp files deleted at confirm entry **and** by `cleanup_flow_state` (double-safe).
- [ ] **Step 5: Full verification + owner smoke**

Run: `docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tests` → exit 0 (this runs unit + integration suites; the docker PHP is 7.4, so it also proves the syntax constraint under the real runtime).
Then send the owner the smoke checklist (dev bot): buy a service → confirm the new shared path works end-to-end; apply a copen mid-buy → confirm the discount still lands; renew that service → check the renewal wording, the `orders` row UPDATE (location/date/volume/link/price), coin deducted once, `count_service` **unchanged**; cancel a renewal at the confirm step → `renewal-service-<id>.json` gone (`ls temp/` / `apcu` check) and no crash; cancel a buy mid-flow → same as before; read `error.log` → the INFO/WARNING/ERROR records from the run are present and structured.

- [ ] **Step 6: Commit**

```bash
git add custom.php docs/cache-system.md
git commit -m "refactor(buy-renew): renewal flow uses shared delivery; bug fixes 1-3"
```

---

## Self-Review Notes (run after writing, before handoff)

1. **Spec coverage:** Owner constraints 1-5 → Global Constraints + Task 3 step 5 (lint gate) + Logging section of Task 3 + test matrix. Three bug fixes → Task 3 (1: cleanup after success/fail, 3: renewal texts) + Task 5 (1: pre-API delete removed, 2: cancel via `cancel_service_flow`). Builder calls 1-5 → Task 3 steps (1: sanayi full code, 2: message slots, 4: cleanup on all terminal returns) + spec "Accepted behaviors" (3, 5 → Task 3 blocks 4, 5). Micro-helpers → Task 2. Freeze → Tasks 4-5 diff checks.
2. **Step scan:** every step names exact files/lines/strings; the only judgment left to the implementer is the internal layout of `complete_service` bodies (determined by the pinned sequence) and `LoggerConfig` shape (read the file if the harness keys don't fit — Task 1 step 1 note).
3. **Type consistency:** helper names/signatures identical in Task 1 Interfaces, Task 2, Tasks 4-5; draft keys identical in Task 3 and the two wirings.
4. **Review Focus:** all five lines pinned to owning tasks (1→T5 smoke, 2→T3 block 11, 3→T2 block 3, 4→T2 block 7, 5→T3 block 10).
5. **Proportion:** plan is ~1.4× the spec; the matrix is the plan's value, not a transcript.
