# Renewal flow review — violations, bugs, efficiency

- **Date:** 2026-11-10
- **Scope:** `renewal_service()` and the Marzban calls it uses: `custom.php:264-462`, `App/apis/marzban-api/api.php:5-93`, `App/Utils/temp_tools.php`, `App/Utils/request_cache.php`, dispatch in `index.php:110-121`, global catch in `index.php:3602`.
- **Commit reviewed:** `4c48f4f` (`main`).
- **Status:** review + proposed patch. The patch has **not** been applied to the working tree.

## Verdict

The wizard is simple, but the money path has several ways to end in one of two bad states:

1. **Panel renewed, user not charged, `orders` stale.** The success message is sent before persistence, and a reset-call failure is treated as a renewal failure even after the update applied.
2. **Renewal attempted on the wrong panel.** The user's server choice is used verbatim while the order already records the hosting panel; `old_server` is stored and then ignored.

On top of that, the flow deletes its own FlowState before doing the work, trusts user text in SQL, and has no tests or audit logging. The existing DRY plan (`docs/superpowers/specs/2026-11-09-buy-renewal-dry-design.md`) fixes two of the ten most serious findings; the four worst are outside its scope.

## Findings

| # | Severity | Finding | Evidence |
|---|---|---|---|
| 1 | Critical | Chosen server is never checked against the order's real server; `old_server` is stored and ignored | `custom.php:289`, `custom.php:335`, `custom.php:375`, `custom.php:450` |
| 2 | Critical | Success/failure is judged on the **reset** call's body, not the update; a reset body without `username` is reported as failure after the PUT applied | `api.php:74-92`, `custom.php:425-433` |
| 3 | Critical | FlowState JSON is deleted before the coin/plan/panel/token/API checks | `custom.php:398` vs `401-423` |
| 4 | Critical | Success message is sent before the `orders` update and coin charge; no transaction | `custom.php:444-446` vs `450`, `453` |
| 5 | High | No idempotency or lock: two fast confirm taps can renew and charge twice | `custom.php:368-453` |
| 6 | High | Missing/expired FlowState and callback updates (null text) silently write nulls instead of failing | `custom.php:312-315`, `328`, `temp_tools.php:22-32`, `index.php:119` |
| 7 | High | Renewal sets expiry to now + days and wipes usage/quota, unlike the existing "extra time" flow that extends from current expiry | `custom.php:423`, `api.php:58-90` vs `index.php:1483`, `1531` |
| 8 | High | String-interpolated SQL with raw user text (injection); plan/panel lookups ignore `status` | `custom.php:281`, `330`, `346`, `384`, `388`, `450`, `453` |
| 9 | Medium | The flow's own back button is consumed as a server/plan name before the global back handler runs | `custom.php:313`, `329` vs `index.php:119`, `123` |
| 10 | Medium | Cancel exists only at confirm and does not delete the renewal JSON or the plan/service-name files | `custom.php:454-460` |
| 11 | Medium | Sanayi orders are renewable and sanayi panels are offered, but both end in "Not implanted Error" or a missing-user API error | `custom.php:388-413`; `get_active_panels()` has no type filter |
| 12 | Medium | Validation order and snapshots: coin before plan; price frozen at select-plan but limit/date re-read at confirm; balance check + charge not atomic; `query()` results unchecked | `custom.php:350-357`, `384-387`, `401-406`, `453` |
| 13 | Low | Purchase wording is reused; a failure sends `renew_error` and then the global catch sends a second generic error | `custom.php:366`, `444-445`, `429-432`; `index.php:3602` |
| 14 | Medium | No logging at all in a flow that charges money | `custom.php:264-462` (no `Logger` call) |

Severity: **Critical** = wrong delivery or free/duplicate renewal; **High** = user-visible dead end, wrong data, or exploitable input; **Medium** = broken UX, stale state, or missing safety net; **Low** = wording/noise.

## Details

### 1. The wrong panel can be renewed

The lookup stores the real panel:

```php
'old_server' => $old_config_location['location']
```

and nothing reads it again. The server step stores any text the user sends (`custom.php:313`), and confirm uses that value for both the API call and the `orders` update (`custom.php:423`, `450`). The keyboard lists **every** active panel, with no hint which one hosts the service.

Consequences:

- If the chosen panel is not the hosting panel, `PUT /api/user/<code>_<id>` returns 404 and the user sees "renewal failed".
- If a user with the same deterministic username exists on the target panel, the bot renews **that** account and then rewrites `orders.location` to the target, so the database now claims the service moved.
- A real migration would require creating the user on the target panel first; that does not exist.

### 2. The update is validated through the reset call

`marzban_renewal_api()` performs the `PUT`, sleeps 1.5 s, then calls `/reset` and returns the **reset** body (`api.php:74-92`). The caller requires `username` in that body (`custom.php:428`), and takes `links` and `subscription_url` from it (`436`, `443`).

So a successful renewal followed by any reset problem (non-user JSON, `detail`, curl error, timeout) is reported as a failed renewal: no charge, no `orders` update, no links, FlowState already deleted. The same happens if the reset body is a valid 2xx without the fields the bot expects.

The `PUT` response is the correct source of truth. The reset is a side effect and should be best-effort.

### 3. FlowState is deleted before the work

`delete_temp_file('renewal-service-...')` runs at `custom.php:398`, before the coin check, plan re-fetch, panel check, type gate, token acquisition and API call. Any failure after that leaves `step = none`, no state and an error message; the user must restart from the beginning. This is the documented bug 1 in the DRY spec.

### 4. Delivery precedes persistence

`sendSuccessResponse()` runs at `444-446`, then the `orders` update at `450`, then the coin update at `453`. There is no transaction. If the Telegram send throws (the non-QR path calls `sendMessage` directly), or the request hits the 120 s cap (`index.php:37`), the panel is renewed but the user is not charged and the order keeps the old date/volume/link/price.

The fix is ordering, not new infrastructure: persist (in a transaction) first, then deliver, and make delivery failure non-fatal.

### 5. Confirm is not idempotent

`step('none')` at `368` reads a user row that was fetched at request start; it is not a lock. Two near-simultaneous "☑️ ایجاد سرویس" updates can both pass every check and run the PUT twice. The second request usually fails on the already-deleted JSON, but only if it starts after the delete; a race that starts earlier doubles the renewal and the charge. There is also no `update_id` deduplication anywhere in dispatch.

### 6. Missing state and callback updates become nulls

`read_renewal_json()` returns `false` on a TTL miss (`open_file()` → `[false, ...]`). In PHP 7.4 `$renewal_data['new_server'] = $text` auto-vivifies an array, so the wizard continues with no `config_name`/`config_base_name`. Notices are suppressed by `error_reporting(E_ALL ^ E_NOTICE)` (`config.php:14`), so this is silent. Confirm then calls `marzban_renewal_api(null, ...)`.

Callback updates are worse: `renewal_service(null, $from_id)` is called for every update (`index.php:119`). Tapping any inline button during the server step writes `null` into `new_server`; at the plan step, `get_panel_by_name(null)` (`custom.php:341`) throws after the step was already set to confirm, so the user lands in a confirm screen whose state cannot complete.

### 7. "Renew" means two different things in this bot

`renewal_service` sets `expire = strtotime("+ $date day")` and `data_limit = plan limit`, then resets usage (`custom.php:423`, `api.php:58-90`). Renewing early discards the remaining days and unused traffic.

The bot already has "buy extra time/volume" (`index.php:1427-1540`), which extends from the current expiry (`index.php:1483`, `1531`). Two paths with opposite semantics for the same word will produce support load. Decide which one "➕ تمدید سرویس" is; if it is an extension, compute the new expiry from `getUserInfo()` instead of `now + days`. This is a product decision, not a bug fix.

### 8. SQL injection and status bypass

These queries interpolate user text directly:

```php
$sql->query("SELECT `location` FROM `orders` WHERE `code` = '$config_base_name' AND `from_id` = '$from_id'")   // 281
$sql->query("SELECT `name` FROM `category` WHERE `name` = '$plan_name'")                                      // 330
$sql->query("SELECT * FROM `category` WHERE `name` = '$plan_name'")->fetch_assoc()                            // 346
$sql->query("SELECT * FROM `category` WHERE `name` = '$plan'")                                                // 384
$sql->query("SELECT * FROM `panels` WHERE `name` = '$location'")                                              // 388
$sql->query("UPDATE `orders` ... WHERE `code` = '$code_base' AND `from_id` = '$from_id'")                     // 450
```

A plan name such as `' OR '1'='1` selects an arbitrary category row, and the same value reaches the API call. The newer code (`get_marzban_panel_token`, `service_overview`) already uses `db_row`/`db_exec` with placeholders; this flow should too.

Separately, the lookups do not filter `status = 'active'`, so typing the name of a deactivated plan or panel bypasses the active lists.

### 9. Back navigation is hijacked

`renewal_service()` runs before the global `🔙 بازگشت` handler (`index.php:119` vs `123`). At the server step, the button is stored as a panel name and then the flow is reset; at the plan step, the invalid name produces no message at all (`custom.php:326-367` has no `else`) and the flow is reset. The intended "go back one step" never happens.

### 10. Cancel is confirm-only and incomplete

`custom.php:454-460` wipes `<id>-location.txt` and `<id>-protocol.txt` plus `service_factors`, but not `renewal-service-<id>.json`, `<id>-plan.txt` or `<id>-service-name.txt`. They linger until the 24 h TTL. Cancelling at the server/plan steps does not exist.

### 11. Sanayi cannot work but is offered

`get_active_panels()` returns every active panel regardless of `type`, and the order lookup ignores `orders.type`. Choosing a sanayi panel reaches `custom.php:411` and prints "Not implanted Error"; choosing a marzban panel for a sanayi-created order calls the API for a username that only exists on the sanayi panel. Both dead-end with no explanation.

### 12. Validation order, snapshots, atomicity

- Coin is checked before plan existence at confirm (`401` vs `406`).
- `new_plan_price` is frozen at select-plan (`357`) but `limit` and `date` are re-read at confirm (`384-387`), so the factor and the delivered service can disagree after an admin edit.
- The price read from FlowState is never validated as numeric.
- `$user['coin'] < $price` then `coin = coin - $price` is a check-then-act with no row lock.
- `$sql->query(...)->fetch_assoc()` / `->num_rows` are used without checking for `false` (`281`, `330`, `346`, `384`, `388`, `392`, `406`); a DB error becomes a fatal.
- The `orders` lookup at `281` has no `LIMIT 1`.

### 13. Wording and double error messages

The factor message uses `service_factor` and the success message uses `success_create_service` (`custom.php:366`, `444-445`) — the DRY spec's bug 3. The caption uses the full username while the body uses the base name, which differs from buy.

On API failure the flow sends `renew_error` and then throws (`429-432`); the global catch sends `error_encounter_msg` with a back-to-menu keyboard (`index.php:3602`), so the user gets two errors.

### 14. No audit trail

There is no `Logger` call in the entire renewal flow. Renewals, failed charges and delivery failures are invisible outside the panel and the database.

## Violations of the repository rules (`AGENTS.md`)

- **Validate preconditions at entry.** Message-step inputs are not validated (`$text` may be `null`, empty, or another flow's button), and the FlowState result is unchecked. Missing keys become nulls through suppressed notices.
- **Throw on contract violations / distinguish them from operational failures.** Both collapse into hidden notices or a generic `Exception` caught by the global handler. There is no `InvalidArgumentException` boundary for draft/state violations.
- **Prepared statements.** `db_row`/`db_exec` exist and enforce placeholder counts, but this flow still interpolates.
- **Tests.** No file under `tests/unit/` or `tests/integration/` mentions renewal. The DRY plan adds tests for `complete_service`, but none for the wizard (back button, cancel, null text, panel match).

## Efficiency

- **Dead query.** `get_panel_by_name($location)` at `custom.php:341`; the result is never used.
- **Duplicate reads.** The same category row is fetched twice at select-plan (`330`, `346`) and a third time at confirm (`384`). The panel is fetched at `388` and again inside `get_marzban_panel_token` (`api.php:8`).
- **Network calls per confirm.** Token probe (`/api/system`), update `PUT`, `sleep(1.5)`, reset `POST`, `getMe` via `file_get_contents` (`custom.php:440`), Telegram send. Plus `isJoin` on every update (`index.php:74-95`). All sequential, with 15–30 s timeouts, inside a 120 s request.
- **Token probe is never cached.** `$token_tested` is request-scoped (`api.php:4`), so every renewal/confirm pays an extra round trip. A reactive refresh on 401 would remove it.
- **`SELECT *`** on `category`/`panels` where a few columns are needed.
- **FlowState split.** APCu when available, file otherwise; if a deployment has APCu on some workers only, state is split between stores. Not a code bug, but worth pinning in `docs/cache-system.md`.

## Proposed patch

Generated against `4c48f4f` and lint-checked (`php -l`) on the patched copies. It is split into two files for review; apply with `git apply` after splitting the blocks into separate `.patch` files, or copy the hunks manually.

What the patch changes:

- `marzban_renewal_api()` returns the `PUT` result (`ok`, `body`, `error`), fails fast on invalid arguments, and no longer sleeps or resets. A new `marzban_reset_user_usage()` does the reset best-effort.
- The confirm branch moves into `renewal_confirm()`: state validation, order-owned panel, plan/panel/type checks, token, API, reset, transaction (row lock + `orders` + coin), cleanup, then delivery.
- The wizard validates text and FlowState at every step, offers only the order's panel, implements real back navigation, and stops wiping `service_factors` (buy flow state).
- Cancel deletes the union of both flows' FlowState files and uses a prepared delete.
- All touched queries use `db_row`/`db_exec`.
- Logging: INFO on success/cancel, WARNING on reset failure/getMe failure/missing state, ERROR on every terminal money-path failure.

Not in the patch: the duplicate-tap lock (shown commented in the branch), the expiry-semantics decision (#7), and the text keys (`success_renew_service`, `renew_service_factor` are used when present, otherwise the purchase keys).

### `custom.php`

```diff
--- a/custom.php
+++ b/custom.php
@@ -275,46 +275,73 @@
         $_renewal_keyboard = json_encode(['keyboard' => $_renewal_keyboard_keys, 'resize_keyboard' => true]);
         sendMessage($from_id, $my_texts['renewal_service_config_name'], $_renewal_keyboard);
     } elseif ($user['step'] == 'renewal_service_get_service_name') {
-        $config_base_name = $text;
-        $config_name = $config_base_name . '_' . $from_id;
-        // deleteMessage($from_id, $message_id);
-        $old_config_location = $sql->query("SELECT `location` FROM `orders` WHERE `code` = '$config_base_name' AND `from_id` = '$from_id'")->fetch_assoc();
-        // $old_config_location = $sql->query("SELECT `location` FROM `orders` WHERE `code` = '$config_base_name'");
-
-        if (isset($old_config_location)) {
-            sendMessage($from_id, $my_texts['renewal_service_config_found'],);
-            $user_array = array(
-                'config_base_name' => $config_base_name,
-                'config_name' => $config_name,
-                'old_server' => $old_config_location['location']
-            );
-            write_renewal_json($from_id, $user_array);
-            $server_rows = get_active_panels();
-            if (count($server_rows) > 0) {
-                if ($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0)
-                    $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
-                foreach ($server_rows as $row) {
-                    $location[] = ['text' => $row['name']];
-                }
-                $location = array_chunk($location, 2);
-                $location[] = [['text' => '🔙 بازگشت']];
-                $location = json_encode(['keyboard' => $location, 'resize_keyboard' => true]);
-                step('renewal_service_choose_server');
-                sendMessage($from_id, $my_texts['renewal_service_server_selection'], $location);
-            } else {
-                sendMessage($from_id, $texts['inactive_buy_service'], $start_key);
-            }
-        } else {
+        $config_base_name = trim((string) $text);
+        if ($config_base_name === '') {
+            $_renewal_keyboard = json_encode(['keyboard' => [[['text' => "⬅️ بازگشت به صفحه اصلی"]]], 'resize_keyboard' => true]);
+            sendMessage($from_id, $my_texts['renewal_service_config_name'], $_renewal_keyboard);
+            return;
+        }
+
+        $order = db_row($sql, "SELECT `location`, `type` FROM `orders` WHERE `code` = ? AND `from_id` = ? LIMIT 1", "ss", array($config_base_name, $from_id));
+        if (!$order) {
             step('renewal_service_get_service_name');
             sendMessage($from_id, $my_texts['renewal_service_config_not_found']);
+            return;
+        }
+        if (isset($order['type']) && $order['type'] !== 'marzban') {
+            Logger::warning('renewal rejected: order is not marzban', array('from_id' => $from_id, 'code' => $config_base_name, 'type' => $order['type']));
+            step('none');
+            sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
+            return;
         }
+
+        sendMessage($from_id, $my_texts['renewal_service_config_found']);
+        write_renewal_json($from_id, array(
+            'config_base_name' => $config_base_name,
+            'config_name' => $config_base_name . '_' . $from_id,
+            'old_server' => $order['location']
+        ));
+
+        // Only the panel that actually hosts the service can be renewed;
+        // a real move would have to create the user on the target first.
+        $servers = json_encode(['keyboard' => [
+            [['text' => $order['location']]],
+            [['text' => '🔙 بازگشت']]
+        ], 'resize_keyboard' => true]);
+        step('renewal_service_choose_server');
+        sendMessage($from_id, $my_texts['renewal_service_server_selection'], $servers);
     } elseif ($user['step'] == 'renewal_service_choose_server') {
         $renewal_data = read_renewal_json($from_id);
+        if (!is_array($renewal_data) || empty($renewal_data['old_server'])) {
+            Logger::warning('renewal state missing at choose_server', array('from_id' => $from_id));
+            step('none');
+            sendMessage($from_id, $my_texts['renewal_service_config_not_found'], $start_key);
+            return;
+        }
+        if ($text === '🔙 بازگشت') {
+            step('renewal_service_get_service_name');
+            $_renewal_keyboard = json_encode(['keyboard' => [[['text' => "⬅️ بازگشت به صفحه اصلی"]]], 'resize_keyboard' => true]);
+            sendMessage($from_id, $my_texts['renewal_service_config_name'], $_renewal_keyboard);
+            exit(0); // handled here, so index.php's later back handler cannot reset it
+        }
+        if ($text !== $renewal_data['old_server']) {
+            $servers = json_encode(['keyboard' => [
+                [['text' => $renewal_data['old_server']]],
+                [['text' => '🔙 بازگشت']]
+            ], 'resize_keyboard' => true]);
+            sendMessage($from_id, $texts['choice_error'] . "\n\n" . $my_texts['renewal_service_server_selection'], $servers);
+            return;
+        }
         $renewal_data['new_server'] = $text;
-
         write_renewal_json($from_id, $renewal_data);
 
         $plan_rows = get_active_categories();
+        if (count($plan_rows) === 0) {
+            step('none');
+            sendMessage($from_id, $texts['inactive_buy_service'], $start_key);
+            return;
+        }
+        $plan = array();
         foreach ($plan_rows as $row) {
             $plan[] = ['text' => $row['name']];
         }
@@ -324,140 +351,256 @@
         step('renewal_service_select_plan');
         sendMessage($from_id, $texts['select_plan'], $plan);
     } elseif ($user['step'] == 'renewal_service_select_plan') {
-        $plan_name = $text;
+        $plan_name = trim((string) $text);
         $renewal_data = read_renewal_json($from_id);
+        if (!is_array($renewal_data) || empty($renewal_data['old_server']) || empty($renewal_data['new_server']) || empty($renewal_data['config_name'])) {
+            Logger::warning('renewal state missing at select_plan', array('from_id' => $from_id));
+            step('none');
+            sendMessage($from_id, $my_texts['renewal_service_config_not_found'], $start_key);
+            return;
+        }
+        if ($plan_name === '🔙 بازگشت') {
+            $servers = json_encode(['keyboard' => [
+                [['text' => $renewal_data['old_server']]],
+                [['text' => '🔙 بازگشت']]
+            ], 'resize_keyboard' => true]);
+            step('renewal_service_choose_server');
+            sendMessage($from_id, $my_texts['renewal_service_server_selection'], $servers);
+            exit(0);
+        }
+
+        // One row, active only. Typed names cannot bypass the active list.
+        $plan_row = db_row($sql, "SELECT * FROM `category` WHERE `name` = ? AND `status` = 'active' LIMIT 1", "s", array($plan_name));
+        if (!$plan_row) {
+            sendMessage($from_id, $texts['choice_error']);
+            return; // stay on this step, unlike today's silent no-op
+        }
+        $price = isset($plan_row['price']) ? $plan_row['price'] : 0;
+        if ($user['coin'] < $price) {
+            step('none');
+            sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
+            return;
+        }
+
         $renewal_data['new_plan'] = $plan_name;
-        $plan_details_sql_response = $sql->query("SELECT `name` FROM `category` WHERE `name` = '$plan_name'")->num_rows;
+        $renewal_data['new_plan_price'] = $price;
+        $renewal_data['new_plan_limit'] = isset($plan_row['limit']) ? $plan_row['limit'] : 0;
+        $renewal_data['new_plan_date'] = isset($plan_row['date']) ? $plan_row['date'] : 0;
+        write_renewal_json($from_id, $renewal_data);
 
-        if ($plan_details_sql_response > 0) {
-            step('renewal_service_confirm_service');
-            sendMessage($from_id, $texts['create_factor'], $confirm_service);
-            $location = $renewal_data['new_server'];
-            $plan = $plan_name;
-            $code = $renewal_data['config_name'];
-            $code_base = $renewal_data['config_base_name'];
+        step('renewal_service_confirm_service');
+        sendMessage($from_id, $texts['create_factor'], $confirm_service);
+        $factor_key = isset($texts['renew_service_factor']) ? 'renew_service_factor' : 'service_factor';
+        sendMessage($from_id, sprintf(
+            $texts[$factor_key],
+            $renewal_data['new_server'],
+            $renewal_data['new_plan_limit'],
+            $renewal_data['new_plan_date'],
+            $renewal_data['config_base_name'],
+            number_format($price)
+        ));
+    } elseif ($user['step'] == 'renewal_service_confirm_service' and $text == '☑️ ایجاد سرویس') {
+        // Money path lives in one place; see renewal_confirm() below.
+        // Optional duplicate-tap guard:
+        // $lock = 'renewal_lock_' . $from_id;
+        // if (function_exists('apcu_add') && !apcu_add($lock, 1, 120)) { sendMessage($from_id, $texts['wait']); return; }
+        // try { renewal_confirm($from_id); } finally { if (function_exists('apcu_delete')) apcu_delete($lock); }
+        renewal_confirm($from_id);
+    } elseif ($text == '❌  انصراف' and $user['step'] == 'renewal_service_confirm_service') {
+        step('none');
+        foreach (array(
+            $from_id . '-location.txt',
+            $from_id . '-protocol.txt',
+            $from_id . '-plan.txt',
+            $from_id . '-service-name.txt',
+            'renewal-service-' . $from_id . '.json'
+        ) as $file_name) {
+            delete_temp_file($file_name);
+        }
+        db_exec($sql, "DELETE FROM `service_factors` WHERE `from_id` = ?", "s", array($from_id));
+        Logger::info('renewal cancelled', array('from_id' => $from_id));
+        sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
+    }
+}
 
 
-            $panel = get_panel_by_name($location);
+/**
+ * Renewal delivery. Order: validate state -> order/panel/plan -> token ->
+ * panel PUT -> persist (transaction) -> cleanup -> message.
+ * The panel has already changed state before persistence, so persistence comes
+ * before delivery and every failure is logged with the code/location.
+ */
+function renewal_confirm($from_id)
+{
+    global $sql, $texts, $my_texts, $start_key, $user;
+
+    step('none');
+    sendMessage($from_id, $texts['create_service_proccess']);
+
+    # ---------------- flow state ----------------
+    $renewal_data = read_renewal_json($from_id);
+    $required = array('config_name', 'config_base_name', 'new_plan', 'new_plan_price');
+    $missing = array();
+    if (!is_array($renewal_data)) {
+        $missing = $required;
+    } else {
+        foreach ($required as $key) {
+            if (!isset($renewal_data[$key]) || $renewal_data[$key] === '') {
+                $missing[] = $key;
+            }
+        }
+    }
+    if (!empty($missing)) {
+        Logger::warning('renewal state missing at confirm', array('from_id' => $from_id, 'missing' => $missing));
+        sendMessage($from_id, $texts['renewal_service_config_not_found'], $start_key);
+        return;
+    }
+
+    $plan = $renewal_data['new_plan'];
+    $price = $renewal_data['new_plan_price'];
+    $code = $renewal_data['config_name'];
+    $code_base = $renewal_data['config_base_name'];
+    if (!is_numeric($price) || $price < 0) {
+        Logger::error('renewal state has invalid price', array('from_id' => $from_id, 'price' => $price));
+        sendMessage($from_id, sprintf($texts['renew_error'], 1), $start_key);
+        return;
+    }
 
-            // $getUser = getUserInfo($code, $panel['token'], $panel['login_link']);
+    # ---------------- the order owns the panel ----------------
+    $order = db_row($sql, "SELECT `location`, `type` FROM `orders` WHERE `code` = ? AND `from_id` = ? LIMIT 1", "ss", array($code_base, $from_id));
+    if (!$order) {
+        Logger::error('renewal order missing', array('from_id' => $from_id, 'code' => $code_base));
+        sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
+        return;
+    }
+    if (isset($order['type']) && $order['type'] !== 'marzban') {
+        Logger::error('renewal order is not marzban', array('from_id' => $from_id, 'code' => $code_base, 'type' => $order['type']));
+        sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
+        return;
+    }
+    if (!isset($renewal_data['new_server']) || $renewal_data['new_server'] !== $order['location']) {
+        Logger::error('renewal panel does not match order', array(
+            'from_id' => $from_id,
+            'code' => $code_base,
+            'chosen' => isset($renewal_data['new_server']) ? $renewal_data['new_server'] : null,
+            'order' => $order['location']
+        ));
+        sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
+        return;
+    }
+    $location = $order['location'];
 
+    # ---------------- plan / panel / type ----------------
+    $plan_row = db_row($sql, "SELECT `limit`, `date` FROM `category` WHERE `name` = ? LIMIT 1", "s", array($plan));
+    if (!$plan_row) {
+        Logger::error('renewal plan missing', array('from_id' => $from_id, 'plan' => $plan));
+        sendMessage($from_id, sprintf($texts['renew_error'], 0), $start_key);
+        return;
+    }
+    $limit = $plan_row['limit'];
+    $date = $plan_row['date'];
 
-            $fetch = $sql->query("SELECT * FROM `category` WHERE `name` = '$plan_name'")->fetch_assoc();
+    $panel = db_row($sql, "SELECT * FROM `panels` WHERE `name` = ? LIMIT 1", "s", array($location));
+    if (!$panel) {
+        Logger::error('renewal panel missing', array('from_id' => $from_id, 'location' => $location));
+        sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
+        return;
+    }
+    if ($panel['type'] !== 'marzban') {
+        sendMessage($from_id, "Not implanted Error", $start_key);
+        return;
+    }
 
+    # ---------------- balance / token ----------------
+    if ($user['coin'] < $price) {
+        sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
+        return;
+    }
+    $token = get_marzban_panel_token($panel['name']);
+    if ($token == false) {
+        sendMessage($from_id, $my_texts['error_show_service__token_is_incorrect'], $start_key);
+        return;
+    }
 
-            $price = $fetch['price'] ?? 0;
-            if ($user['coin'] < $price) {
-                step('none');
-                sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
-                exit(0);
-            }
-            $limit = $fetch['limit'] ?? 0;
-            $date = $fetch['date'] ?? 0;
-            $renewal_data['new_plan_price'] = $price;
-            $renewal_data['new_plan_limit'] = $limit;
-            $renewal_data['new_plan_date'] = $date;
-
-            write_renewal_json($from_id, $renewal_data);
-
-            // $sql->query("INSERT INTO `service_factors` (`from_id`, `location`, `protocol`, `plan`, `price`, `code`, `status`) VALUES ('$from_id', '$location', 'null', '$plan', '$price', '$code', 'active')");
-            // $copen_key = json_encode(['inline_keyboard' => [[['text' => '🎁 کد تخفیف', 'callback_data' => 'use_copen-' . $code]]]]);
-            // sendMessage($from_id, sprintf($texts['service_factor'], $location, $limit, $date, $code, number_format($price)), $copen_key);
-            sendMessage($from_id, sprintf($texts['service_factor'], $location, $limit, $date, $code_base, number_format($price)));
-        }
-    } elseif ($user['step'] == 'renewal_service_confirm_service' and $text == '☑️ ایجاد سرویس') {
-        step('none');
-        sendMessage($from_id, $texts['create_service_proccess']);
+    # ---------------- panel update (source of truth) ----------------
+    $renewal = marzban_renewal_api($code, convertToBytes($limit . 'GB'), strtotime("+ $date day"), $token, $panel['login_link']);
+    if (!$renewal['ok']) {
+        Logger::error('Marzban renewal API error', array(
+            'from_id' => $from_id, 'code' => $code, 'location' => $location, 'detail' => $renewal['error']
+        ));
+        sendMessage($from_id, sprintf($texts['renew_error'], 1), $start_key);
+        return;
+    }
+    $body = $renewal['body'];
+    if (!isset($body['links']) || !is_array($body['links']) || !isset($body['subscription_url'])) {
+        Logger::error('Marzban renewal response incomplete', array(
+            'from_id' => $from_id, 'code' => $code, 'keys' => is_array($body) ? array_keys($body) : array()
+        ));
+        sendMessage($from_id, sprintf($texts['renew_error'], 1), $start_key);
+        return;
+    }
 
-        # ---------------- get all information for create service ---------------- #
-        // $select_service = $sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->fetch_assoc();
-        $renewal_data = read_renewal_json($from_id);
-        $location = $renewal_data['new_server'];
-        $plan = $renewal_data['new_plan'];
-        $price = $renewal_data['new_plan_price'];
-        $code = $renewal_data['config_name'];
-        $code_base = $renewal_data['config_base_name'];
-        // $status = $select_service['status'];
-        // $name = base64_encode($code) . '_' . $from_id;
-        $name = $code;
-        $name_base = $code_base;
-        $get_plan = $sql->query("SELECT * FROM `category` WHERE `name` = '$plan'");
-        $get_plan_fetch = $get_plan->fetch_assoc();
-        $date = $get_plan_fetch['date'] ?? 0;
-        $limit = $get_plan_fetch['limit'] ?? 0;
-        $info_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$location'");
-        $panel = $info_panel->fetch_assoc();
-
-        # --------------- check if panel exists -------------- #
-        if ($info_panel->num_rows == 0) {
-            sendMessage($from_id, sprintf($texts['create_error'], 2), $start_key);
-            throw new Exception("Panel exists check failed: panel '$location' not found in database.");
-        }
+    # ---------------- reset is best effort ----------------
+    $reset = marzban_reset_user_usage($code, $token, $panel['login_link']);
+    if (!$reset['ok']) {
+        Logger::warning('Marzban renewal reset failed', array(
+            'from_id' => $from_id, 'code' => $code, 'detail' => $reset['error']
+        ));
+    }
 
-        # ---------------- delete extra files ---------------- #
-        delete_temp_file('renewal-service-' . $from_id . '.json');
+    # ---------------- persist before messaging ----------------
+    $links = "";
+    foreach ($body['links'] as $link) {
+        $links .= $link . "\n\n";
+    }
+    $subscribe = (strpos($body['subscription_url'], 'http') !== false) ? $body['subscription_url'] : $panel['login_link'] . $body['subscription_url'];
 
-        # ---------------- check coin for create service ---------------- #
-        if ($user['coin'] < $price) {
-            sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
-            exit();
+    $sql->begin_transaction();
+    try {
+        // Re-check the balance under the row lock; $user['coin'] is request-start stale.
+        $balance = db_row($sql, "SELECT `coin` FROM `users` WHERE `from_id` = ? LIMIT 1 FOR UPDATE", "s", array($from_id));
+        if (!$balance || $balance['coin'] < $price) {
+            throw new RuntimeException('insufficient coin at charge time');
         }
-        # ---------------- check database ----------------#
-        if ($get_plan->num_rows == 0) {
-            sendMessage($from_id, sprintf($texts['create_error'], 0), $start_key);
-            throw new Exception("Plan check failed: plan '$plan' not found in category table.");
-        }
-        # ---------------- create service proccess ---------------- #
-        if ($panel['type'] !== 'marzban') {
-            sendMessage($from_id, "Not implanted Error", $start_key);
-            exit();
+        if (!db_exec($sql, "UPDATE `orders` SET `location` = ?, `date` = ?, `volume` = ?, `link` = ?, `price` = ? WHERE `code` = ? AND `from_id` = ?", "sssssss", array($location, $date, $limit, $links, $price, $code_base, $from_id))) {
+            throw new RuntimeException('orders update failed');
         }
-        # ---------------- create service ---------------- #
-        $token = get_marzban_panel_token($panel['name']);
-
-        if ($token == false) {
-            step('none');
-            sendMessage($from_id, $my_texts['error_show_service__token_is_incorrect'], $start_key);
-            exit();
+        if (!db_exec($sql, "UPDATE `users` SET `coin` = coin - ? WHERE `from_id` = ? LIMIT 1", "ss", array($price, $from_id))) {
+            throw new RuntimeException('coin update failed');
         }
-        $renewal_service = marzban_renewal_api($name, convertToBytes($limit . 'GB'), strtotime("+ $date day"), $token, $panel['login_link']);
+        $sql->commit();
+    } catch (\Throwable $e) {
+        $sql->rollback();
+        Logger::error('renewal persistence failed', array('from_id' => $from_id, 'code' => $code_base, 'error' => $e->getMessage()));
+        sendMessage($from_id, sprintf($texts['renew_error'], 1), $start_key);
+        return;
+    }
 
-        $renewal_status = json_decode($renewal_service, true);
+    delete_temp_file('renewal-service-' . $from_id . '.json');
+    Logger::info('renewal completed', array(
+        'from_id' => $from_id, 'code' => $code_base, 'location' => $location, 'plan' => $plan, 'price' => $price
+    ));
+
+    # ---------------- delivery (failure is logged, not fatal) ----------------
+    $bot_username = '*';
+    try {
+        $me = getMe();
+        if (isset($me['result']['username'])) {
+            $bot_username = $me['result']['username'];
+        }
+    } catch (\Throwable $e) {
+        Logger::warning('getMe failed during renewal delivery', array('from_id' => $from_id, 'error' => $e->getMessage()));
+    }
 
-        # ---------------- check errors ---------------- #
-        if (!isset($renewal_status['username'])) {
-            step('none');
-            sendMessage($from_id, sprintf($texts['renew_error'], 1), $start_key);
-            // sendMessage($from_id, sprintf($renewal_status['username'], 1), $start_key);
-            throw new Exception("Marzban renewal API error: " . json_encode($renewal_status));
-        }
-        # ---------------- get links and subscription_url for send the user ---------------- #
-        $links = "";
-        foreach ($renewal_status['links'] as $link){
-            $links .= $link . "\n\n";
-        }
-        
-        $getMe = json_decode(file_get_contents("https://api.telegram.org/bot{$config['token']}/getMe"), true);
-        $bot_username = isset($getMe['result']) ? $getMe['result']['username'] : '*';
-
-        $subscribe = (strpos($renewal_status['subscription_url'], 'http') !== false) ? $renewal_status['subscription_url'] : $panel['login_link'] . $renewal_status['subscription_url'];
-        $caption_text = sprintf($texts['success_create_service'], $name, $location, $date, $limit, number_format($price), '', '@' . $bot_username);
-        $message_text = sprintf($texts['success_create_service'], $name_base, $location, $date, $limit, number_format($price), $subscribe, '@' . $bot_username);
+    // Falls back to purchase wording until the DRY plan adds success_renew_service.
+    $success_key = isset($texts['success_renew_service']) ? 'success_renew_service' : 'success_create_service';
+    $caption_text = sprintf($texts[$success_key], $code, $location, $date, $limit, number_format($price), '', '@' . $bot_username);
+    $message_text = sprintf($texts[$success_key], $code_base, $location, $date, $limit, number_format($price), $subscribe, '@' . $bot_username);
+    try {
         sendSuccessResponse($from_id, $panel['qr_code'], $subscribe, $caption_text, $message_text, $start_key);
-        // `code` holds the BASE plan name, shared by every customer of that plan.
-        // Without `from_id` this UPDATE overwrote the location, date, volume,
-        // link and price of every other customer's order for the same plan.
-        $sql->query("UPDATE `orders` SET `location` = '$location', `date` = '$date', `volume` = '$limit', `link` = '$links', `price` = '$price' WHERE `code` = '$code_base' AND `from_id` = '$from_id'");
-
-        # ---------------- Deduct Coins  ---------------- #
-        $sql->query("UPDATE `users` SET `coin` = coin - $price WHERE `from_id` = '$from_id' LIMIT 1");
-    } elseif ($text == '❌  انصراف' and $user['step'] == 'renewal_service_confirm_service') {
-        step('none');
-        delete_temp_file($from_id . '-location.txt');
-        delete_temp_file($from_id . '-protocol.txt');
-        if ($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0)
-            $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
-        sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
+    } catch (\Throwable $e) {
+        Logger::error('renewal delivery failed', array('from_id' => $from_id, 'code' => $code_base, 'error' => $e->getMessage()));
+        sendMessage($from_id, $texts['qr_unavailable_hint'], $start_key);
     }
 }
 
```

### `App/apis/marzban-api/api.php`

```diff
--- a/App/apis/marzban-api/api.php
+++ b/App/apis/marzban-api/api.php
@@ -45,6 +45,17 @@
 
 function marzban_renewal_api($username, $new_traffic_limit, $new_expire_time, $token, $url)
 {
+    // Fail fast on caller bugs; operational failures below return a result array.
+    if (!is_string($username) || $username === '') {
+        throw new InvalidArgumentException('marzban_renewal_api: username must be a non-empty string');
+    }
+    if (!is_string($token) || $token === '') {
+        throw new InvalidArgumentException('marzban_renewal_api: token must be a non-empty string');
+    }
+    if (!is_string($url) || $url === '') {
+        throw new InvalidArgumentException('marzban_renewal_api: url must be a non-empty string');
+    }
+
     $ch1 = curl_init();
     curl_setopt($ch1, CURLOPT_URL, $url . "/api/user/$username");
     // curl_setopt($ch, CURLOPT_PUT, true);
@@ -68,28 +79,65 @@
             )
         )
     );
-    $new_limit_response = curl_exec($ch1);
+    $raw = curl_exec($ch1);
+    $curl_error = curl_error($ch1);
     curl_close($ch1);
 
-    sleep(1.5);
+    if ($raw === false) {
+        Logger::warning('Marzban renewal update failed (curl)', array('username' => $username, 'error' => $curl_error));
+        return array('ok' => false, 'body' => null, 'error' => $curl_error);
+    }
+
+    $body = json_decode($raw, true);
+    if (!is_array($body) || !isset($body['username'])) {
+        $detail = is_array($body) && isset($body['detail']) ? $body['detail'] : null;
+        Logger::warning('Marzban renewal update rejected', array('username' => $username, 'detail' => $detail));
+        return array(
+            'ok' => false,
+            'body' => is_array($body) ? $body : null,
+            'error' => $detail !== null ? $detail : 'invalid response'
+        );
+    }
+
+    // The PUT body is the source of truth for delivery (links, subscription_url).
+    return array('ok' => true, 'body' => $body, 'error' => null);
+}
 
-    $new_limit_response = json_decode($new_limit_response, true);
-    if (isset($new_limit_response['username'])) {
-        $ch2 = curl_init();
-        curl_setopt($ch2, CURLOPT_URL, $url . "/api/user/$username/reset");
-        curl_setopt($ch2, CURLOPT_POST, true);
-        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
-        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
-        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 2);
-        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 30);
-        curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
-        curl_setopt($ch2, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
-        $reset_data_usage_response = curl_exec($ch2);
-        curl_close($ch2);
-        return $reset_data_usage_response;
-    } else {
-        return $new_limit_response;
+/**
+ * Reset a user's data usage after renewal. Best effort: the renewal is already
+ * applied, so the caller logs a warning instead of failing the delivery.
+ *
+ * @return array{ok: bool, body: array|null, error: string|null}
+ */
+function marzban_reset_user_usage($username, $token, $url)
+{
+    if (!is_string($username) || $username === '') {
+        throw new InvalidArgumentException('marzban_reset_user_usage: username must be a non-empty string');
+    }
+    $ch = curl_init();
+    curl_setopt($ch, CURLOPT_URL, $url . "/api/user/$username/reset");
+    curl_setopt($ch, CURLOPT_POST, true);
+    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
+    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
+    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
+    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
+    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
+    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
+    $raw = curl_exec($ch);
+    $curl_error = curl_error($ch);
+    curl_close($ch);
+    if ($raw === false) {
+        return array('ok' => false, 'body' => null, 'error' => $curl_error);
+    }
+    $body = json_decode($raw, true);
+    if (!is_array($body) || isset($body['detail'])) {
+        return array(
+            'ok' => false,
+            'body' => is_array($body) ? $body : null,
+            'error' => is_array($body) && isset($body['detail']) ? $body['detail'] : 'invalid response'
+        );
     }
+    return array('ok' => true, 'body' => $body, 'error' => null);
 }
 
 
```

## Post-patch behavior

| Finding | Status after patch |
|---|---|
| 1 wrong panel | Fixed: the server step offers only the order's panel; confirm re-checks it. |
| 2 reset-response validation | Fixed: the `PUT` body is the source of truth; reset failure is a warning. |
| 3 delete before work | Fixed: cleanup happens after success only. |
| 4 message before persistence | Fixed: transaction, then cleanup, then message. |
| 6 null / missing FlowState | Fixed at all three wizard steps and confirm. |
| 8 SQL injection in the flow | Fixed on touched queries; `step()` and the buy flow still interpolate. |
| 9 back button | Fixed: both wizard steps handle `🔙 بازگشت` and `exit(0)`. |
| 10 cancel cleanup | Fixed: union of files + prepared delete. |
| 11 sanayi orders | Rejected explicitly; panels can no longer be crossed. |
| 12 atomicity / validation | Improved: `FOR UPDATE` balance check, placeholders, `LIMIT 1`, snapshot of plan row. |
| 5 duplicate confirm | **Not fixed**; only the 3-line APCu lock is provided, commented. |
| 7 expiry semantics | **Not fixed**; product decision. |
| 13 wording | Fallback only; the DRY plan adds the renewal text keys. |
| 14 logging | Fixed for the renewed path. |

## Tests to add

None exist for renewal today. With the patch:

- `tests/unit/renewal_confirm_test.php` — state missing, invalid price, order missing, non-marzban order, panel mismatch, plan missing, panel missing, type gate, coin failure, token failure, API failure, incomplete API body, reset failure (warning, still delivered), persistence failure (rollback, no message), success (exact SQL, coin delta, cleanup, message slots).
- `tests/unit/marzban_renewal_api_test.php` — curl `false`, `detail` body, valid body, reset success/failure.
- `tests/unit/renewal_wizard_test.php` — null/empty text, back at server step, back at plan step, invalid plan keeps the step, cancel clears all five files.

`tests/unit/run.sh` picks up `*_test.php` automatically; no new dependency.

## Relationship to the DRY plan

`docs/superpowers/specs/2026-11-09-buy-renewal-dry-design.md` already covers:

- #3 (delete before work), as bug 1.
- #10 partially (cancel deletes the union), as bug 2.
- #13 (renewal wording), as bug 3.
- #14 for `complete_service()` (structured logging).

It does **not** cover #1, #2, #4, #5, #6, #7, #8, #11. #1, #2 and #4 are the ones that cause wrong deliveries or free renewals, so they matter more than the DRY cleanup itself. If the DRY plan is implemented first, port the `api.php` hunks and the ordering from `renewal_confirm()` into `complete_service()`.

## Recommended order

1. **Money-path correctness:** #1 (order-owned panel), #2 (validate the `PUT`), #4 (persist before message).
2. **State safety:** #6 (validate every step) and #5 (idempotency lock).
3. **Product rules:** #7 (extend vs reset) and #11 (sanayi).
4. **Input and observability:** #8 (placeholders/status filters), #12, #14.
5. **Wizard polish:** #9, #10, #13.

The patch in this document implements 1, 2, 3, 6, 8, 9, 10, 11 and 14; 5 is left as a commented guard for review.

## Notes on the patch

- `marzban_renewal_api()` changes its return contract from "JSON string" to `array{ok, body, error}`. Its only caller is `custom.php:423`; the DRY spec's constraint to keep the reset inside that function is superseded deliberately.
- `sleep(1.5)` is removed. If the panel needs a settle delay, measure it and use `usleep()`; do not carry an unexplained 1.5 s.
- `getMe()` replaces `file_get_contents()`; it has a 15 s cURL timeout and throws on failure, which the patch catches.
- The `success_renew_service` / `renew_service_factor` keys are used when present, so the patch works before and after the DRY text change.
- The patch was lint-checked only. Integration behavior needs the manual smoke from the DRY plan (buy, renew, cancel at each step, copen, check `orders`, coin, `count_service`, FlowState cleanup, log records).
