# Renewal Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the renewal money path safe: order-owned panel, `PUT` as source of truth, persist-before-deliver, validated state, working back/cancel, duplicate-tap lock, structured logs, and branch tests.

**Architecture:** A new side-effect-free module `App/functions/renewal_confirm.php` owns confirm; `App/apis/marzban-api/api.php` changes `marzban_renewal_api()` to return a result array and splits the reset into a best-effort helper; `custom.php` keeps the wizard but adds per-step guards, real back navigation, complete cancel, and delegates confirm. Tests are zero-dependency stubs per repo convention.

**Tech Stack:** PHP 7.4, mysqli (`db_row`/`db_exec`), cURL, APCu (fail-open), `Logger`.

**Spec:** `docs/superpowers/specs/2026-11-10-renewal-hardening-design.md` (read it first; it carries the failure map and the owner's incomplete-body decision). Full review with the exact current-code line numbers: `docs/reviews/2026-11-10-renewal-flow-review.md`.

## Global Constraints

- PHP **7.4** syntax only; mysqli; no new dependencies or extensions; runtime image `php:7.4-cli`.
- `db_row`/`db_exec` (App/Utils/db_tools.php): placeholder-count contract violations throw `InvalidArgumentException`; driver/runtime errors return `null`/`false`. All new queries in the touched path use them.
- User-facing strings live in `App/texts.json` only; new code adds at most the one key `renew_persist_failed`.
- FlowState file `renewal-service-<id>.json` via `read_renewal_json()`/`write_renewal_json()`; format additive only; step names frozen: `renewal_service_get_service_name`, `renewal_service_choose_server`, `renewal_service_select_plan`, `renewal_service_confirm_service`.
- Renewal semantics unchanged: expiry = `now + plan days`, usage reset.
- Single release: `api.php` + `renewal_confirm.php` + `custom.php` ship together; no intermediate state where the old confirm calls the new API contract.
- `marzban_renewal_api()` return contract: `array{ok: bool, body: array|null, error: string|null}`.
- Default `LOG_LEVEL` is `WARNING`: guaranteed audit trail uses WARNING/ERROR; INFO only for success/cancel.
- Lint command (works in this environment): `cat FILE | docker run -i --rm php:8.2-cli php -l`.
- Unit tests run via `tests/unit/run.sh` (auto-discovers `*_test.php`); full suite via `docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tests`.

## Review Focus

- **Double-tap confirm race** (two "☑️ ایجاد سرویس" callbacks ~1 s apart): second tap gets `wait`, exactly one PUT and one charge. Pinned by: Task 2 (add/exist/fallback states) + Task 6 smoke "duplicate confirm".
- **Host without ext-apcu**: `apcu_safe_add` returns `null`, flow proceeds (fail-open), one warning. Pinned by: Task 2 test "returns null without the extension".
- **Panel down / curl timeout mid-confirm** (15–30 s calls inside the 120 s request cap): `renew_error 1`, no charge, no orders change, ERROR log. Pinned by: Task 3 unit test (curl-error path) + Task 6 smoke "renew with panel down".
- **In-flight user whose FlowState predates the deploy** (keys written by the old select-plan: `new_plan`, `new_plan_price`, `new_server` all present; older: missing keys): new confirm validates every key; missing ⇒ `renewal_service_config_not_found`, no money moves. Pinned by: Task 5 unit test (missing-state case).
- **Admin edits the plan between select_plan and confirm**: charge and `orders.price` use the price frozen in FlowState at select_plan; `limit`/`date` are re-read from the plan at confirm. Pinned by: Task 5 unit test (success case asserts coin delta = state price and orders params = re-read limit/date).

---

### Task 1: `marzban_parse_api_result()` — pure response parser

**Files:**
- Modify: `App/apis/marzban-api/api.php` (add one function at the end of the file)
- Test: `tests/unit/marzban_api_result_test.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `marzban_parse_api_result($raw, $curl_error = null): array{ok: bool, body: array|null, error: string|null}` — `$raw` is the raw response string or `false`. Rules (from the spec): `false` ⇒ `ok=false, error=$curl_error`; non-array JSON or no `username` ⇒ `ok=false, error=detail when present else 'invalid response', body=array when decodable else null`; valid ⇒ `ok=true, body=decoded, error=null`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/marzban_api_result_test.php` (pattern: `tests/unit/apcu_tools_test.php` — require `helper.php` then the source file):

```php
<?php
// Unit tests for marzban_parse_api_result (pure; no network).
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/apis/marzban-api/api.php';

$valid = json_encode(array('username' => 'u_1', 'links' => array('vless://x'), 'subscription_url' => 'https://sub/x'));

$r = marzban_parse_api_result(false, 'timed out');
assert_eq(false, $r['ok'], 'curl failure is not ok');
assert_eq('timed out', $r['error'], 'curl failure carries the curl error');

$r = marzban_parse_api_result('not json');
assert_eq(false, $r['ok'], 'non-JSON is not ok');
assert_eq('invalid response', $r['error'], 'non-JSON error text');
assert_eq(null, $r['body'], 'non-JSON body is null');

$r = marzban_parse_api_result(json_encode(array('detail' => 'User not found')));
assert_eq(false, $r['ok'], 'detail body is not ok');
assert_eq('User not found', $r['error'], 'detail surfaced as error');
assert_eq(array('detail' => 'User not found'), $r['body'], 'decodable body kept');

$r = marzban_parse_api_result(json_encode(array('foo' => 'bar')));
assert_eq(false, $r['ok'], 'body without username is not ok');

$r = marzban_parse_api_result($valid);
assert_eq(true, $r['ok'], 'valid body is ok');
assert_eq(null, $r['error'], 'valid body has no error');
assert_eq('u_1', $r['body']['username'], 'valid body decoded');

echo "OK marzban_api_result_test.php\n";
exit(0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/unit/marzban_api_result_test.php`
Expected: FAIL — `marzban_parse_api_result` undefined (fatal).

- [ ] **Step 3: Implement the function in `App/apis/marzban-api/api.php`**

Append at end of file. Approach: `json_decode` the raw string; `is_array` + `username` gate; `detail` passthrough. No network, no globals.

- [ ] **Step 4: Run test to verify it passes**

Same command. Expected: `OK marzban_api_result_test.php`.

- [ ] **Step 5: Commit**

```bash
git add tests/unit/marzban_api_result_test.php App/apis/marzban-api/api.php
git commit -m "feat(renewal): add pure marzban API response parser"
```

---

### Task 2: `apcu_safe_add()` — atomic duplicate-tap lock primitive

**Files:**
- Modify: `App/Utils/apcu_tools.php` (add one function)
- Test: `tests/unit/apcu_tools_test.php` (extend)

**Interfaces:**
- Consumes: `apcu_warn_once()` (existing).
- Produces: `apcu_safe_add($key, $value, $ttl = 0): bool|null` — `true` when the key was added, `false` when it already exists, `null` when ext-apcu is missing (fail-open, warn once). Throws `InvalidArgumentException` on empty/non-string key, like its siblings.

- [ ] **Step 1: Write the failing test**

Append to `tests/unit/apcu_tools_test.php` (before the final `echo "OK ..."`):

```php
// apcu_safe_add: tri-state contract.
// In the plain CLI image ext-apcu is absent => null (fail-open, no fatal).
if (function_exists('apcu_add')) {
    apcu_clear_cache();
    assert_eq(true, apcu_safe_add('add:key', 1, 120), 'apcu_safe_add adds a fresh key');
    assert_eq(false, apcu_safe_add('add:key', 2, 120), 'apcu_safe_add reports an existing key');
} else {
    assert_eq(null, apcu_safe_add('add:key', 1, 120), 'apcu_safe_add returns null without the extension');
    assert_eq(null, apcu_safe_add('add:key', 1, 120), 'repeated miss stays null, no fatal');
}
assert_throws(function() {
    apcu_safe_add('', 1);
}, InvalidArgumentException::class, 'apcu_safe_add throws on empty key');
assert_throws(function() {
    apcu_safe_add(null, 1);
}, InvalidArgumentException::class, 'apcu_safe_add throws on null key');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/unit/apcu_tools_test.php`
Expected: FAIL — `apcu_safe_add` undefined.

- [ ] **Step 3: Implement in `App/Utils/apcu_tools.php`**

Same shape as `apcu_safe_set` but built on `apcu_add` (atomic check-and-set): key validation, `function_exists('apcu_add')` gate, `apcu_warn_once()` + `return null` on the missing-extension path, else `return apcu_add($key, $value, $ttl)`.

- [ ] **Step 4: Run test to verify it passes**

Same command. Expected: `OK apcu_tools_test.php`. Also confirm the ext-apcu branch compiles: `cat App/Utils/apcu_tools.php | docker run -i --rm php:8.2-cli php -l`.

- [ ] **Step 5: Commit**

```bash
git add tests/unit/apcu_tools_test.php App/Utils/apcu_tools.php
git commit -m "feat(renewal): apcu_safe_add atomic lock primitive (fail-open)"
```

---

### Task 3: New `marzban_renewal_api()` contract + `marzban_reset_user_usage()`

**Files:**
- Modify: `App/apis/marzban-api/api.php` (rewrite `marzban_renewal_api()`; add `marzban_reset_user_usage()`)
- Test: `tests/unit/marzban_api_result_test.php` (extend)

**Interfaces:**
- Consumes: `marzban_parse_api_result()` (Task 1), `Logger`.
- Produces:
  - `marzban_renewal_api($username, $new_traffic_limit, $new_expire_time, $token, $url): array{ok, body, error}` — throws `InvalidArgumentException` before any network call when `username`, `token`, or `url` is not a non-empty string. cURL PUT to `$url/api/user/$username` (existing options, `CURLOPT_PUT`, JSON body `{data_limit, expire}` unchanged). On curl `false`: log WARNING `Marzban renewal update failed (curl)`, return parse result. Otherwise return `marzban_parse_api_result($raw, $curl_error)`; log WARNING `Marzban renewal update rejected` with `detail` when `ok=false`. **No `sleep`, no reset call, no return-type change to a string.**
  - `marzban_reset_user_usage($username, $token, $url): array{ok, body, error}` — throws on non-string/empty `username`; cURL POST `$url/api/user/$username/reset` with the same options as the current reset block; parse with `marzban_parse_api_result()`; additionally treat a body containing `detail` as failure.

- [ ] **Step 1: Write the failing tests** (extend `tests/unit/marzban_api_result_test.php` before its final `echo`):

```php
// marzban_renewal_api: contract violations throw before any network call.
assert_throws(function() {
    marzban_renewal_api('', 1, 1, 't', 'https://p');
}, InvalidArgumentException::class, 'renewal_api throws on empty username');
assert_throws(function() {
    marzban_renewal_api('u', 1, 1, '', 'https://p');
}, InvalidArgumentException::class, 'renewal_api throws on empty token');
assert_throws(function() {
    marzban_renewal_api('u', 1, 1, 't', '');
}, InvalidArgumentException::class, 'renewal_api throws on empty url');
assert_throws(function() {
    marzban_reset_user_usage(null, 't', 'https://p');
}, InvalidArgumentException::class, 'reset throws on non-string username');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/unit/marzban_api_result_test.php`
Expected: FAIL — `marzban_reset_user_usage` undefined (the throw tests pass once the function exists; the missing function is what fails).

- [ ] **Step 3: Rewrite `marzban_renewal_api()` and add `marzban_reset_user_usage()`**

Reference behavior (argument validation, cURL options, log messages) is in the review's second ` ```diff ` block: `docs/reviews/2026-11-10-renewal-flow-review.md`. Do **not** copy its inline parse code — both functions there parse the JSON by hand; the single parse site is `marzban_parse_api_result()` (Task 1). The two functions:

```php
function marzban_renewal_api($username, $new_traffic_limit, $new_expire_time, $token, $url)
{
    // reference's three InvalidArgumentException guards, unchanged
    $ch1 = curl_init();
    // reference's CURLOPT_* block for PUT $url/api/user/$username, unchanged
    $raw = curl_exec($ch1);
    $curl_error = curl_error($ch1);
    curl_close($ch1);
    if ($raw === false) {
        Logger::warning('Marzban renewal update failed (curl)', array('username' => $username, 'error' => $curl_error));
        return array('ok' => false, 'body' => null, 'error' => $curl_error);
    }
    $r = marzban_parse_api_result($raw, $curl_error);
    if (!$r['ok']) {
        Logger::warning('Marzban renewal update rejected', array('username' => $username, 'detail' => $r['error']));
    }
    return $r;
}

function marzban_reset_user_usage($username, $token, $url)
{
    // reference's InvalidArgumentException guard on username, unchanged
    $ch = curl_init();
    // reference's CURLOPT_* block for POST $url/api/user/$username/reset, unchanged
    $raw = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);
    $r = marzban_parse_api_result($raw, $curl_error);
    if ($r['ok'] && isset($r['body']['detail'])) {
        // reference behavior: a reset body carrying `detail` is a failure even with `username`
        $r = array('ok' => false, 'body' => $r['body'], 'error' => $r['body']['detail']);
    }
    return $r;
}
```

Keep `get_marzban_panel_token()` untouched.

- [ ] **Step 4: Run tests and lint**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/unit/marzban_api_result_test.php` → `OK`.
Run: `cat App/apis/marzban-api/api.php | docker run -i --rm php:8.2-cli php -l` → no errors.
Run: `grep -n "sleep" App/apis/marzban-api/api.php` → no matches (the 1.5 s sleep is gone).

- [ ] **Step 5: Commit**

```bash
git add App/apis/marzban-api/api.php tests/unit/marzban_api_result_test.php
git commit -m "feat(renewal): PUT result contract, best-effort reset, no sleep"
```

---

### Task 4: `renew_persist_failed` text

**Files:**
- Modify: `App/texts.json`

**Interfaces:**
- Consumes: nothing.
- Produces: key `renew_persist_failed` (Persian, one `%s` for the service code, meaning: renewal applied on the panel but the record could not be saved — contact support with the code). Used by Task 5's persistence-failure branch.

- [ ] **Step 1: Add the key**

Insert next to `"renew_error"` in `App/texts.json`, matching the existing style:

```json
"renew_persist_failed": "⚠️ تمدید روی پنل اعمال شد اما ثبت آن در ربات با خطا مواجه شد. لطفاً با کد <code>%s</code> به پشتیبانی اطلاع دهید.",
```

- [ ] **Step 2: Verify JSON validity**

Run: `php -r '$j = json_decode(file_get_contents("App/texts.json"), true); exit(is_array($j) && isset($j["renew_persist_failed"]) ? 0 : 1);'` — expect exit 0. (If `php` is not installed locally, run it inside the `php:8.2-cli` image with the repo mounted.)

- [ ] **Step 3: Commit**

```bash
git add App/texts.json
git commit -m "feat(renewal): add renew_persist_failed text"
```

---

### Task 5: `App/functions/renewal_confirm.php` — the money path (TDD)

**Files:**
- Create: `App/functions/renewal_confirm.php`
- Test: `tests/unit/renewal_confirm_test.php` (create)

**Interfaces:**
- Consumes: `db_row`, `db_exec` (or test stubs), `marzban_renewal_api()`, `marzban_reset_user_usage()` (or stubs), `step()`, `sendMessage()`, `read_renewal_json()`, `delete_temp_file()`, `getMe()`, `sendSuccessResponse()`, `Logger` (or stubs). Globals: `$sql, $texts, $my_texts, $start_key, $user`.
- Produces:
  - `renewal_confirm($from_id)` — void; never throws out; order per the spec's numbered list: step('none') + proccess message → state validation → order (marzban, panel match) → plan/panel/type → coin → token → `marzban_renewal_api()` → accepted-but-incomplete body: WARNING, empty delivery, continue → `marzban_reset_user_usage()` (WARNING on failure) → transaction (`SELECT coin ... FOR UPDATE` → `UPDATE orders` → `UPDATE users`) → rollback+`renew_persist_failed` on failure → `delete_temp_file('renewal-service-<id>.json')` + INFO → delivery (caught; failure ⇒ `qr_unavailable_hint`).
  - `renewal_state_missing($data = null): array` — returns the missing keys of `config_name`, `config_base_name`, `new_plan`, `new_plan_price`, `new_server` (a value counts as missing when `!isset` or `''`); `null` or non-array input ⇒ all five.
  - `renewal_links_text($body): string` — joins `body['links']` with `"\n\n"`; `''` when absent/not array.
  - `renewal_subscribe_url($sub_url, $panel_base): string` — `$sub_url` verbatim when it starts with `http`, else `$panel_base . $sub_url`; `''` when `$sub_url` is empty.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/renewal_confirm_test.php`. Structure (mirror `tests/unit/send_success_response_test.php`): define all stubs **before** `require_once`-ing the module; the module must have no top-level side effects and no `require` of its own.

Stubs (record into arrays for assertions):

```php
$GLOBALS['stub_rows'] = array();      // db_row query => row|false
$GLOBALS['stub_exec'] = array();      // db_exec query => bool
$GLOBALS['renewal_api_result'] = null; // array set per case
$GLOBALS['reset_result'] = array('ok' => true, 'body' => array(), 'error' => null);
$GLOBALS['sent'] = array();           // sendMessage calls
$GLOBALS['delivered'] = array();      // sendSuccessResponse calls
$GLOBALS['deleted'] = array();        // delete_temp_file calls
$GLOBALS['steps'] = array();          // step() calls
$GLOBALS['renewal_state'] = array();  // read_renewal_json value
$GLOBALS['logs'] = array();           // Logger calls
```

`function db_row($sql, $query, $types = '', $params = [])` returns the matching stubbed row keyed by the full query string, or `false` (assert the placeholder count matches first, like the real one — copy the check from `db_tools.php`). `function db_exec(...)` records the call (query, params) and returns the stubbed bool or `true`. `class FakeSql` with `begin_transaction()`, `commit()`, `rollback()` recording into `$GLOBALS['tx'] = array('begin'=>0,'commit'=>0,'rollback'=>0)`. `class Logger` — a stub with `public static $calls = array();` and `public static function __callStatic($m, $a) { self::$calls[] = array($m, $a[0]); }`; the real `App/Utils/Logger.php` is **not** required in this test, so the stub is the only definition. `getMe()` and `sendSuccessResponse()` are stubbed here (the real files are not required in this test either).

Helper to reset globals between cases; helper `case_setup()` sets the happy-path stubs (order row `location='srv1', type='marzban'`; plan row `limit='100', date='30'`; panel row `name='srv1', type='marzban', login_link='https://p', token='t', qr_code='active'`; `renewal_state` with all five keys, price `5000`; `renewal_api_result` with `ok, body{username, links[...], subscription_url}`; `$user['coin']=10000`).

Cases (each: reset, mutate one stub, call `renewal_confirm(7)`, assert):

1. **missing state** — empty `renewal_state`: `sent` contains `renewal_service_config_not_found`; no `renewal_api_result` call; no tx begin.
2. **invalid price** — price `'abc'`: `renew_error` with 1; no API call.
3. **order missing** — `db_row` order ⇒ `false`: `renew_error` with 2; no API call.
4. **non-marzban order** — `type='sanayi'`: `renew_error` with 2.
5. **panel mismatch** — state `new_server='srv2'`: `renew_error` with 2; `Logger::error` recorded with `renewal_panel_mismatch` (assert message string).
6. **plan missing** — plan row ⇒ `false`: `renew_error` with 0.
7. **panel missing** / **non-marzban panel** — panel row `false` / `type='sanayi'`: `renew_error` 2 / existing `"Not implanted Error"` message.
8. **coin short** — `$user['coin']=100`: `not_coin` message; no API call, no tx.
9. **token failure** — stub `get_marzban_panel_token` ⇒ `false` (if not stubbed, set `token_tested` and a panel row with bad token is hard — simplest: define a stub `function get_marzban_panel_token($n){ return false; }` in the test, the real one only exists when api.php is included; in this test the module is included **without** api.php so the stub is safe): `error_show_service__token_is_incorrect`; no API call.
10. **API failure** — `renewal_api_result = array(ok:false, body:null, error:'detail')`: `renew_error` with 1; no reset call; no tx; ERROR log with `renewal_api_failed`.
11. **accepted but incomplete body** — `ok:true, body: array(username:'u')` only: delivered anyway (`delivered` count 1, message text has no sub URL); coin charged; tx commit; WARNING log. *(Owner decision 2026-11-10.)*
12. **reset failure** — `reset_result ok:false`: still delivered, coin charged, commit; WARNING log with `renewal_reset_failed`.
13. **persistence failure** — `db_exec` for the coin UPDATE ⇒ `false`: tx `rollback==1`; `sent` contains the `renew_persist_failed` text (sprintf with the code); **no** `sendSuccessResponse` call; no file deleted.
14. **delivery failure** — stub `sendSuccessResponse` throws: `sent` contains `qr_unavailable_hint`; no exception escapes; file **is** deleted; commit already happened.
15. **success (the big one)** — happy path; assert: `steps[0]=='none'`; `delivered` exactly 1 and its args: caption/body built from `success_renew_service`-or-fallback key with `code`, `location`, `date`, `limit`, `number_format(price)`, subscribe URL, `@username`; `db_exec` calls in order: orders UPDATE with params `(location, date, limit, links, price, code_base, from_id)` types `sssssss`, then coin UPDATE `coin - ?` with `($price, $from_id)` types `ss`; `tx = begin 1, commit 1, rollback 0`; `deleted` contains `renewal-service-7.json`; **delivery happened after commit** (assert via call ordering: record a sequence number in each stub).
16. **renewal_state_missing() unit cases** — full array ⇒ `[]`; missing one key ⇒ that key; `null` ⇒ all five.
17. **helper units** — `renewal_links_text` with/without links; `renewal_subscribe_url` absolute vs relative vs empty.

`$texts` stub: include all keys used (`create_service_proccess`, `renew_error`, `not_coin`, `choice_error`, `renewal_service_config_not_found`, `qr_unavailable_hint`, `renew_persist_failed` with one `%s` — case 13 asserts on it, `success_create_service`, `service_factor` — the fallback pair; do **not** set `success_renew_service`, so the fallback is exercised). `$my_texts = $texts`. `$start_key = 'key'`.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/unit/renewal_confirm_test.php`
Expected: FAIL — `renewal_confirm` undefined.

- [ ] **Step 3: Implement `App/functions/renewal_confirm.php`**

Header comment: "Renewal delivery. Order: validate state → order/panel/plan → token → panel PUT → persist (transaction) → cleanup → message. No top-level side effects; resolves all helpers at call time so tests can stub them."

Implement the 4 functions per the Interfaces block and the spec's numbered order + failure map. Notes:

- `sprintf($texts['renew_error'], N)` — `renew_error` is `"%s | 🚫 تمدید سرویس با خطا مواجه شد."`, first arg is the code.
- Success key: `$key = isset($texts['success_renew_service']) ? 'success_renew_service' : 'success_create_service'`; caption uses the full `$code`, body uses `$code_base` (mirrors the buy-flow split).
- Transaction body: `db_row($sql, "SELECT \`coin\` FROM \`users\` WHERE \`from_id\` = ? LIMIT 1 FOR UPDATE", "s", array($from_id))`; `UPDATE \`orders\` SET \`location\` = ?, \`date\` = ?, \`volume\` = ?, \`link\` = ?, \`price\` = ? WHERE \`code\` = ? AND \`from_id\` = ?` with `sssssss`; `UPDATE \`users\` SET \`coin\` = coin - ? WHERE \`from_id\` = ? LIMIT 1` with `ss`. Any `null`/`false`/insufficient balance ⇒ `throw new RuntimeException(...)`, caught by one `catch (\\Throwable $e)` that rolls back, logs `renewal_persist_failed`, sends the text, returns.
- Bot username: `getMe()` in try/catch; on failure keep `'*'` and log WARNING.
- Delivery: `sendSuccessResponse($from_id, $panel['qr_code'], $subscribe, $caption_text, $message_text, $start_key)` in try/catch; on throw log ERROR `renewal_delivery_failed` and `sendMessage($from_id, $texts['qr_unavailable_hint'], $start_key)`.
- No `sleep` anywhere. PHP 7.4 syntax only.

- [ ] **Step 4: Run test to verify it passes**

Same command → all cases pass, `OK`. Lint: `cat App/functions/renewal_confirm.php | docker run -i --rm php:8.2-cli php -l`.

- [ ] **Step 5: Commit**

```bash
git add App/functions/renewal_confirm.php tests/unit/renewal_confirm_test.php
git commit -m "feat(renewal): renewal_confirm money path (persist before deliver)"
```

---

### Task 6: `custom.php` — wizard guards, back/cancel, confirm delegation

**Files:**
- Modify: `custom.php` (`renewal_service()` region, currently `~264-462`)
- No unit test (the file runs top-level bootstrap on include; behavior is pinned by the smoke matrix below and by Task 5's tests for the delegated confirm).

**Interfaces:**
- Consumes: `renewal_confirm()` (Task 5), `apcu_safe_add()`/`apcu_safe_del()` (Task 2), `db_row`, existing `step`/`sendMessage`/`write_renewal_json`/`read_renewal_json`/`delete_temp_file`, `$texts`, `$my_texts`, `$start_key`, `$user`, `$sql`.

- [ ] **Step 1: Add the require**

In `custom.php`, next to its other `require_once` lines (top of file):

```php
require_once __DIR__ . "/App/functions/renewal_confirm.php";
```

- [ ] **Step 2: Transcribe the wizard hunks from the review reference**

The exact target code for each branch is in `docs/reviews/2026-11-10-renewal-flow-review.md`, first ` ```diff ` block (the `custom.php` section, the `+` lines). Replace, in order:

1. The `renewal_service_get_service_name` branch → the new version (trim/empty guard, `db_row` lookup with `LIMIT 1`, non-marzban order rejection, one-panel keyboard, `exit(0)` on back not applicable here).
2. The `renewal_service_choose_server` branch → the new version (FlowState guard, back ⇒ name step + `exit(0)`, exact-match check with `choice_error`, active-categories guard).
3. The `renewal_service_select_plan` branch → the new version (state guard incl. `old_server`, back ⇒ server step + `exit(0)`, `db_row` active plan lookup, coin check, snapshot write, `renew_service_factor`-or-fallback factor message).
4. The `renewal_service_confirm_service` + `'☑️ ایجاد سرویس'` branch → the delegation below (this is the one **changed** relative to the reference — the reference shows a commented-out lock; ship the live one):

```php
    } elseif ($user['step'] == 'renewal_service_confirm_service' and $text == '☑️ ایجاد سرویس') {
        // Money path lives in one place; see renewal_confirm().
        // Duplicate-tap guard: atomic add, 120s TTL (covers the request cap),
        // released on every exit path. Without ext-apcu this degrades to no lock.
        $lock_key = 'renewal_lock_' . $from_id;
        $locked = apcu_safe_add($lock_key, 1, 120);
        if ($locked === false) {
            sendMessage($from_id, $texts['wait']);
            return;
        }
        try {
            renewal_confirm($from_id);
        } finally {
            if ($locked === true) {
                apcu_safe_del($lock_key);
            }
        }
```

5. The `'❌  انصراف'` branch → the new version (deletes all five files incl. `renewal-service-<id>.json` and `<id>-plan.txt`/`<id>-service-name.txt`, `db_exec` prepared delete of `service_factors`, `Logger::info('renewal_cancelled', ...)`, start message).
6. Delete the entire old inline confirm body (the reference diff's removed lines: coin/plan/panel checks, `marzban_renewal_api` call, links build, `getMe`, `sendSuccessResponse`, `orders`/`users` updates) — replaced by item 4.

- [ ] **Step 3: Verify**

Run: `cat custom.php | docker run -i --rm php:8.2-cli php -l` → no errors.
Run: `grep -n "query(\"" custom.php | sed -n '1,40p'` → the renewal region has no remaining string-interpolated queries (all touched queries now use `db_row`/`db_exec`; interpolation elsewhere in the file is out of scope).
Run the full unit suite (`run.sh` invokes bare `php`, so run it with the CLI image): `docker run --rm -v "$PWD":/app -w /app php:8.2-cli bash tests/unit/run.sh` → all PASS.

- [ ] **Step 4: Smoke matrix (manual, on a test bot + real test panel — record results in the commit message or a note)**

1. Renew success: fresh buy, then renew; verify message (links + sub URL), `orders` row updated (location/date/volume/link/price), coin deducted, `renewal-service-<id>.json` gone, `INFO renewal_completed` log at `LOG_LEVEL=INFO`.
2. Renew with panel down: no charge, `orders` unchanged, `renew_error` shown, `ERROR renewal_api_failed` log.
3. Reset endpoint down (block `/reset`): renewal still succeeds + charges, `WARNING renewal_reset_failed` log.
4. Cancel at name/server/plan/confirm: all five files gone after each; `service_factors` cleaned.
5. Back at server step ⇒ name prompt; back at plan step ⇒ server prompt; tapping an inline (callback) button mid-step does not crash and does not corrupt state.
6. Duplicate confirm: two fast "☑️ ایجاد سرویس" taps ⇒ second shows `wait`; exactly one charge, one `renewal_completed`.
7. In-flight state from before the deploy (old `renewal-service-<id>.json`): flow completes or shows the missing-state message; no money moves on the bad case.
8. Buy flow + copen: unchanged, one full buy.
9. Sanayi order entered into renewal: rejected with `renew_error 2`, no API call.

- [ ] **Step 5: Commit**

```bash
git add custom.php
git commit -m "feat(renewal): wizard guards, order-owned panel, back/cancel, confirm delegation + tap lock"
```

---

### Task 7: Full suite + deploy gate

**Files:** none (verification only)

- [ ] **Step 1: Full test suite**

Run: `docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tests`
Expected: all unit + integration tests pass (PHP 7.4 image — this is the syntax gate).

- [ ] **Step 2: InnoDB check (spec's deploy gate)**

On a production DB (read-only): `SHOW TABLE STATUS WHERE Name IN ('users','orders');` — confirm `Engine=InnoDB` for both (required for the `FOR UPDATE` row lock to be a real lock; if MyISAM, stop and report before deploy).

- [ ] **Step 3: Docs**

Add to `docs/cache-system.md` §5 (or a new subsection): the `renewal_lock_<id>` APCu key (120 s TTL, released in `finally`, fail-open without ext-apcu), and a note that the usage reset no longer lives inside `marzban_renewal_api()`.

- [ ] **Step 4: Commit**

```bash
git add docs/cache-system.md
git commit -m "docs(renewal): renewal lock key and reset relocation"
```

---

## Out of Scope (for the executor)

- Expiry semantics (extend vs reset) — product decision, separate.
- Buy flow, shared `complete_service()` — the DRY plan (`docs/superpowers/plans/2026-11-09-buy-renewal-dry.md`); it will adopt this ordering.
- Repo-wide SQL interpolation sweep; `step()` in `config.php` still interpolates.
- New renewal wording keys (`success_renew_service`, `renew_service_factor`) — owned by the DRY plan; this pass falls back to the purchase keys when absent.
- `App/functions/service_overview.php` interpolation (read path, out of this flow).
