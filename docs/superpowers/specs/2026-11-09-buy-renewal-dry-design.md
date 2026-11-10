# Buy / Renewal DRY — Shared Confirm & Delivery

Date: 2026-11-09
Status: proposed

## Intent & Why

The buy service flow (`index.php:192-472`) and the renewal flow
(`custom.php:264-461`) are two wizards over the same domain operation:
charge a user, provision a Marzban service, record it in `orders`, send
the success message. Their final "confirm and deliver" blocks are ~110
lines each and already drifted apart, and renewal carries latent bugs
the drift created. Goal: one shared delivery path, zero behavior change
to the two wizards themselves.

## Goals

- Single `complete_service()` delivery path used by both flows: plan/panel
  load, validation, coin check, token, create-or-renew, links, success
  message, `orders` write, coin deduction, FlowState cleanup.
- Fix the three latent renewal bugs (below) as a byproduct, in one place.
- No change to wizard steps, step names, user-facing flow order, or
  FlowState file formats. In-flight users are unaffected; no migration.

## Non-Goals

- No unified state machine / no step-name changes / no UX flow change.
- No sanayi support in renewal (stays marzban-only; renewal of sanayi
  services is a separate feature).
- No discount codes (copen) in renewal (stays buy-only, as today).
- No SQL-injection / prepared-statement overhaul (repo-wide, separate project).
- No schema changes. `orders`, `service_factors`, `users` untouched.
- No changes to buy's name-creation logic (dup checks, ≤20 chars).
- No changes to token-memoization (`$token_tested`) behavior.

## Anti-Goals (what would make this a failure)

- A user mid-buy or mid-renewal sees changed screens, step order, or gets
  stuck after deploy (step names or FlowState format changed).
- Renewal stops working for users whose panel is sanayi in a new way
  (today: "Not implanted Error" message; must stay an explicit rejection).
- Buy flow gains a sanaji-only failure mode it didn't have, or loses the
  copen discount path.
- Coin is deducted when the panel API call fails (true today; must stay).

## Constraints

- Legacy PHP **7.4** + mysqli string-interpolated SQL style (runtime is
  `php:7.4-cli`, `tests/integration/test.Dockerfile:1`). New code matches
  the file it lives in; fail-fast per AGENTS.md (contract violations throw
  `InvalidArgumentException`, operational failures handled with user message).
  No PHP 8+ syntax anywhere in the new module (see Owner constraints).
- All user-facing strings live in `App/texts.json`.
- `users.step` values and FlowState file names/formats are observable to
  in-flight users and to `docs/cache-system.md`; they are frozen.
- The buy confirm entry must keep working from the `use_copen`/`cancel_copen`
  callback path (`index.php:329-356`).
- `marzban_renewal_api()` keeps its internal `sleep(1.5)` and reset-call
  behavior; only its caller moves.

## Approaches considered

- **A — Shared confirm + delivery (chosen).** One `complete_service()`
  handles everything after the user taps "ایجاد سرویس". Wizards stay
  per-flow. No step-name/UX/FlowState change; in-flight users safe; bugs
  fixed in the one place that matters. Trade-off: wizard-level duplication
  (keyboards, plan fetch) stays.
- **B — Unify the whole wizard** behind a mode flag in step names.
  Maximal DRY, but rewrites `users.step` values for in-flight users
  (needs stuck-user migration), merges two cancel paths, and risks UX drift
  on a live bot. Rejected: highest risk for the same payoff.
- **C — Micro-helpers only** (keyboard builders, cleanup, message builder).
  Safest, but leaves the two confirm blocks side by side — exactly where
  they drifted and where the bugs live. Rejected: doesn't remove the risk.

## Decisions made (by the owner, 2026-11-09)

| Decision | Choice |
|---|---|
| Approach | A — shared confirm + delivery |
| Renewal panel types | Marzban only (sanayi renewal stays a feature request) |
| Copen in renewal | Buy-only, unchanged |
| Renewal wording | Separate renewal texts in `texts.json` (stop reusing purchase wording) |

## Owner constraints (2026-11-10)

1. **PHP 7.4 syntax only.** The runtime image is `php:7.4-cli`
   (`tests/integration/test.Dockerfile:1`). No 8.0+ constructs: no
   `match`, `str_contains`/`str_starts_with`, named arguments,
   constructor property promotion, enums, readonly properties,
   `mixed`/`never` types.
2. **The wizard steps must not become complicated.** No state machine,
   no mode-flag threading through the step chain. Each flow's
   if/elseif step chain stays flat and readable; shared logic sits
   behind plain functions with explicit arguments.
3. **One place to maintain the shared steps, as far as that stays
   simple.** The common pieces (keyboards, confirm presentation,
   delivery, cleanup) live in one module called by both flows. The
   genuinely different pieces (name creation vs lookup, copen) stay
   per-flow rather than being forced into a common shape.
4. **Robust logging at three levels** — info, warning, error — through
   the existing `Logger` (`App/Utils/Logger.php`), with structured
   context arrays, covering both the happy path and every failure
   branch of the shared path.
5. **Unit tests as robust as possible**: every branch of the shared
   path is asserted for its user message, its DB statements, its
   FlowState cleanup, its coin effect, its panel-API call (or
   non-call), and its log records.

## Latent bugs fixed by this change

1. **Renewal FlowState deleted before the API call** (`custom.php:398`).
   If `marzban_renewal_api` then fails, the renewal state JSON is gone, the
   user's step is reset, and nothing was delivered or charged — a dead end.
   Fix: cleanup happens inside `complete_service()` after success only.
2. **Renewal cancel never deletes `renewal-service-<id>.json`**
   (`custom.php:454-460` only deletes buy-flow files + `service_factors`).
   The JSON lingers to the 24h TTL. Fix: shared cancel cleanup deletes the
   union of both flows' files (deleting a missing file is a no-op).
3. **Renewal shows purchase wording** ("✅ خرید سرویس با موفقیت انجام شد",
   "فاکتور ... ساخته شد"). Fix: new renewal text keys; renewal confirm and
   success use them.

## Design

### New file: `App/functions/complete_service.php`

Required from `custom.php`'s existing require block (top of file), so both
`index.php` and `custom.php` have it in every entry point that loads
`custom.php`. (Builder may place the require in `index.php` before
`custom.php` if cleaner; the function must be defined before either
confirm branch runs.)

```php
/**
 * Shared buy/renewal delivery, runs after the user confirms.
 *
 * $mode: 'buy' | 'renew'
 * $draft: {
 *   'location'  => panel name (string, must exist in `panels`),
 *   'plan'      => category name (string, must exist in `category`),
 *   'price'     => final price in coins (int; for buy this is the
 *                  service_factors price, i.e. after any copen discount),
 *   'code_base' => base service/plan name (orders.code),
 *   'code'      => marzban username: "$code_base_$from_id"
 * }
 * Returns ['ok' => true, 'reason' => null] on success, or
 *         ['ok' => false, 'reason' =>
 *            'coin'|'plan_missing'|'panel_missing'|'panel_type'|'token'|'api_error']
 * On every non-ok return, the user has already been messaged and the flow
 * step is 'none'. On 'api_error' an Exception is still thrown (matches
 * today's logging behavior in both flows).
 */
function complete_service($mode, $from_id, array $draft): array
```

### Shared micro-helpers (one module, called by both wizards)

Per constraint 3, the duplicated wizard pieces move here too; each is a
plain function, no shared state, no step-name changes:

- `service_options_keyboard(array $rows)`: builds the 2-per-row keyboard
  with the trailing back button from `name`/button text. Used for the
  server list (both flows) and the plan list (both flows). Replaces
  `index.php:198-203`, `216-221`, `custom.php:296-301`, `318-323`.
- `inactive_service_message($from_id)`: the "no active panels" message
  + start keyboard (replaces `index.php:205-207`, `custom.php:305`).
- `wipe_service_factors($from_id)`: the SELECT-then-DELETE guard
  (replaces `index.php:196-197`, `custom.php:294-295`).
- `category_plan_details($plan_name)`: the `category` row by name, or
  false. No message — the caller decides (buy: `choice_error` + exit,
  renew: silent, exactly as today).
- `send_service_factor_message($from_id, $flow, $location, $limit,
  $date, $code_base, $price)`: the factor message with the per-flow text
  key (`service_factor` / `renew_service_factor`), no keyboard.
- `get_bot_username($decoded_me = null)`: getMe helper with the `'*'`
  fallback; `$decoded_me` accepts a pre-decoded array so unit tests
  never touch the network (production passes nothing).
- Message order in each wizard's pre-confirm step is preserved exactly
  (buy: `create_factor` → factor message; renew: `create_factor` →
  coin pre-check → factor message). The `create_factor` +
  `$confirm_service` keyboard line stays in each caller (one line,
  identical in both).
- `cleanup_flow_state($from_id)` + `cancel_service_flow($from_id)`:
  shared union delete (five FlowState files + `service_factors` wipe)
  and the cancel handler (cleanup + step 'none' + greeting). Both
  flows' cancel branches call `cancel_service_flow`.

### Shared confirm sequence (both modes, in this order — matches today's
order of message → orders write → coin deduction)

1. Load category row (`$draft['plan']`) and panel row (`$draft['location']`).
   Plan missing → `create_error` 0 (buy) / `renew_error` 0 (renew),
   reason `plan_missing`, no API call, no charge.
   Panel missing → `create_error` 2 / `renew_error` 2, reason `panel_missing`.
2. Panel-type gate: `marzban` allowed in both modes; `sanayi` allowed in
   buy only; anything else → "Not implanted Error" message (kept verbatim),
   reason `panel_type`.
3. Coin check: `$user['coin'] < $draft['price']` → `not_coin`, reason
   `coin`, no API call. (Same as today: checked before the panel call.)
4. Token acquisition (mode-specific hook, kept as-is per flow):
   buy → `loginPanel(...)`; renew → `get_marzban_panel_token($panel['name'])`.
   Failure → `error_show_service__token_is_incorrect`, reason `token`.
5. Panel API:
   - buy: build `$proxies`/`$inbounds` exactly as `index.php:392-412` does
     today (protocols + flow + `marzban_inbounds` by `panel.code`), then
     `createService(...)`.
   - renew: `marzban_renewal_api($code, convertToBytes(limit.'GB'),
     strtotime("+ $date day"), $token, $panel['login_link'])`.
   API failure → `create_error` 1 / `renew_error` 1, reason `api_error`,
   throw Exception (as both flows do today). No coin deduction, no
   FlowState deletion, step 'none'.
6. Limit/date come from the category row (both flows re-fetch it at confirm
   time; keep that).
7. Links: join `links` with `"\n\n"`; normalize `subscription_url` (prefix
   `login_link` unless it already starts with `http`) — identical in both
   flows today, one code path.
8. Bot username via getMe — one shared helper; keep renewal's existing
   `isset` fallback (`'*'`), which is strictly more defensive than buy's
   bare access.
9. Success message: `sendSuccessResponse(...)` with per-mode texts
   (see Texts below). Per-mode service-name slots keep **today's exact
   values** (builder note, call made during review — zero behavior change):
   buy → `$code_base` in both caption and text message (`index.php:437-438`);
   renew → full `$code` in the photo caption, `$code_base` in the text
   message (today's `custom.php:444-445`). The asymmetry between the two
   slots in renewal is preserved, not "fixed" — changing it is a wording
   decision for another day.
10. Orders write:
    - buy: `INSERT INTO orders ... type = panel type` (marzban/sanayi rows
      as today, `index.php:440` / `index.php:464`). **Builder note (call
      made during review):** the two INSERTs are not uniform — the sanayi
      branch stores the **full** `$code` (`code_base_from_id`) in
      `orders.code` (`index.php:464`), while marzban stores `$code_base`
      (`index.php:440`). Lookups such as `getQrCode` (`index.php:1377`)
      and the `serv_stat` sanayi branch depend on the stored shape. Keep
      both shapes exactly as today; the draft's `code_base`/`code` fields
      feed each branch independently, so the contract above ("code_base
      = orders.code") holds for marzban and renew, not for sanayi buy.
    - renew: `UPDATE orders SET location, date, volume, link, price
      WHERE code = code_base AND from_id` (as today, with its existing
      comment about the `from_id` guard).
11. FlowState cleanup: run on **every terminal return** from
    `complete_service` — success and all failure reasons alike. The step
    is reset to 'none' on every failure too, so the flow is over either
    way and a lingering FlowState is pure staleness (the bug-1/bug-2
    class). This extends bug 2's fix from the cancel handler to the
    failure paths:
    - buy: delete `<id>-location.txt`, `<id>-protocol.txt`, `<id>-plan.txt`,
      `<id>-service-name.txt`; `DELETE FROM service_factors WHERE from_id`.
    - renew: delete `renewal-service-<id>.json`.
    Order inside the success path: panel API → message → orders write →
    FlowState cleanup → coin deduction (message→orders→coins is today's
    order; cleanup moves before the coin write only because on the
    failure paths it must run without charging).
12. Coin deduction:
    - buy: `coin - price, count_service + 1`.
    - renew: `coin - price` only (no `count_service`).

Buy's sanayi branch (`index.php:446-469`) moves into step 10 as the
`type == 'sanayi'` path; its success message is
`success_create_service_sanayi`. Sanayi in renew never reaches step 5
(rejected at step 2).

### Logging (constraint 4)

Every log goes through `Logger` (`App/Utils/Logger.php`) with a
structured context array (`'flow' => 'buy'|'renew'` in every record).
`$from_id`, `$code`, `$location`, `$plan` are log-safe (ids and names);
never log the panel token, panel password, or full API payloads.

- **INFO** — the shared path entered (`complete_service`, with the
  whole draft), and every terminal outcome: success (with orders row
  action and coin delta), and each failure reason.
- **WARNING** — degraded-but-handled cases: token refresh needed
  (buy's `loginPanel` retry path is unchanged; log when the first token
  attempt fails and a refresh is attempted), panel API returned an
  error body (log the decoded `detail` field only), FlowState missing
  at cancel (file not found — TTL expired), getMe fallback to `'*'`
  (renewal's existing guard), and any `sendSuccessResponse` fallback
  to text (already logged inside that function).
- **ERROR** — contract violations (fail-fast, thrown
  `InvalidArgumentException` before it is thrown: draft with missing/
  non-string fields, mode not buy/renew) and unrecoverable outcomes:
  panel missing, plan missing, panel type unsupported, token
  acquisition failed, panel API failed (with the `detail`), coin
  insufficient, and the thrown `api_error` Exception path.

Each failure branch logs exactly once at its level; the user message
and the log are emitted together in the same branch (no log-only or
message-only exits).

### What stays per-flow (wizards, unchanged)

- Buy: `🛒 خرید سرویس` entry + `service_factors` wipe (`index.php:192-207`),
  `buy_service` server step (with its panel-name validation,
  `index.php:208-224`), `select_plan` step, `choose_name` step (name
  validation, panel dup check, `service_factors` INSERT, factor message),
  copen callback path, confirm entry that builds the draft from
  `service_factors` + temp files.
- Renew: `➕ تمدید سرویس` entry, `renewal_service_get_service_name`
  (orders lookup), `renewal_service_choose_server`,
  `renewal_service_select_plan` (plan existence, price/limit/date, factor
  message — now with renewal wording), confirm entry that builds the draft
  from the renewal JSON.
- Each flow's cancel handler stays, but both call one shared
  `cleanup_flow_state($from_id)` that deletes the union:
  `<id>-location.txt`, `<id>-protocol.txt`, `<id>-plan.txt`,
  `<id>-service-name.txt`, `renewal-service-<id>.json`, plus the existing
  `service_factors` wipe and the restart message. Fixes bug 2.

### Texts (`App/texts.json`, new keys)

- `success_renew_service`: renewal success, mirrors `success_create_service`
  with "تمدید" wording.
- `renew_service_factor`: renewal confirm-factor message, mirrors
  `service_factor` with "تمدید" wording.
Existing `renew_error` already exists and stays. Wording: builder mirrors
the existing Persian style; owner reviews the two strings before merge.

### Testing

PHP 7.4, zero-dependency, `tests/unit/`, same
mock-then-require-then-assert pattern as
`tests/unit/send_success_response_test.php`.

- **`tests/unit/complete_service_test.php`** — stub before require:
  `loginPanel`, `get_marzban_panel_token`, `createService`,
  `marzban_renewal_api`, `sendSuccessResponse`, `step`, temp-file
  helpers (`write_temp_file`/`read_temp_file`/`delete_temp_file` on an
  in-memory array), `convertToBytes`, a fake `$sql` with a scriptable
  `query()`/`fetch_assoc`/`num_rows` (scripted per-statement results),
  `$user`, `$texts`, and a recording `Logger` stub (via
  `Logger::setInstance` or a same-name stub, following the existing
  test conventions) capturing `(level, msg, context)` tuples.
  Assert per branch: returned reason, user message text + keyboard,
  every SQL statement issued (exact string match on the
  INSERT/UPDATE/DELETE), FlowState files deleted, `service_factors`
  wipe, coin delta (buy: `-price, +1 count_service`; renew: `-price`
  only), panel-API call vs non-call (buy: `createService` args incl.
  proxies/inbounds; renew: `marzban_renewal_api` args), and the log
  records (level + `flow` context + reason).
  Branch matrix (each = one named test block):
  1. buy marzban happy path (incl. flow=flowon proxy shape and
     `marzban_inbounds` mapping).
  2. buy sanayi happy path (sanayi delivery shape, full-code
     `orders.code`, `success_create_service_sanayi` text).
  3. renew happy path.
  4. `coin` failure (both modes): message, no API call, no coin
     change, FlowState still cleaned, ERROR log.
  5. `plan_missing` (both modes): no API call, ERROR log (dead-guard,
     exercised by stub).
  6. `panel_missing` (both modes).
  7. renew + sanayi panel → `panel_type` rejection, no API call.
  8. buy + unknown panel type → `panel_type` rejection, "Not
     implanted Error" verbatim.
  9. `token` failure (both modes, each mode's token path).
  10. `api_error` (both modes): message, ERROR log with `detail`,
      Exception thrown, no coin change, FlowState cleaned.
  11. draft contract violations (missing field, bad mode, non-string
      code) → `InvalidArgumentException` (fail-fast per AGENTS.md).
  12. success-message slot values: buy caption/message use
      `code_base`; renew caption uses full `code`, message uses
      `code_base`.
  13. subscription_url normalization: absolute stays, relative gets
      `login_link` prefix (both modes).
- **`tests/unit/service_flow_helpers_test.php`** — the micro-helpers:
  `service_options_keyboard` output shape (chunk-2 rows, trailing back
  button, resize flag) for 0, 1, 2, 3, 5, 7 items; `wipe_service_factors`
  deletes only when rows exist; `cleanup_flow_state`/`cancel_service_flow`
  delete the full union of five files + factors wipe + greeting message,
  and are safe when nothing exists (TTL-expired FlowState → WARNING log,
  no fatal).
- **Integration**: `docker compose -f docker-compose.test.yml up
  --build --abort-on-container-exit --exit-code-from tests` passes.
- **Manual smoke (dev bot)**: buy a service; renew it; cancel each
  midway through each step; apply a copen in buy; verify `orders` row
  (both code shapes), coin balance, `count_service`, FlowState files
  gone after cancel/finish, and that the log shows the info/warning/
  error records from the run.

## Left to the builder

- Exact require placement for the shared module (see New file above);
  both `index.php` and `custom.php` must load it before either confirm
  branch runs.
- The two Persian text strings (mirror existing style; owner reviews).
- How mode-specific token acquisition is expressed (param or internal
  branch — internal branch is fine, it's 2 lines).
- The unit-test stub set (any additional stubs needed to load the file).
- Whether the sanayi buy delivery (today `index.php:446-469`, including
  the `Sanayi` client call and its `example_link` substitution) moves
  into the shared module or stays in `index.php` and is dispatched to
  from step 5/10 by panel type — either keeps behavior identical; pick
  whichever keeps `index.php`'s confirm branch shortest. The sanayi
  client (`api/sanayi.php`) stays where it is.

## Accepted behaviors (noted during builder review, kept as today)

- **Copen double-check asymmetry.** The buy wizard gates at `select_plan`
  on the **pre-discount** category price, while confirm gates on the
  post-copen `service_factors` price. A user whose balance sits between the
  two can hit `not_coin` at confirm. That's today's behavior; aligning the
  wizard gate (re-checking the discounted price in the copen callback) is
  out of scope. The shared path keeps exactly this: wizard pre-check stays
  where it is; `complete_service` checks the final price.
- **`plan_missing` is a dead guard under today's wizards.** Both wizards
  validate plan existence before confirm (and neither filters on
  `status`, so a mid-flow deactivation fails here too). Keep the check as
  pure defense in `complete_service`; the unit test exercises it directly
  by stubbing the category row out — it is not a reachable user-facing
  error, and its wording (`create_error` 0 / `renew_error` 0) needs no
  owner review.

## Out of scope (noted, not fixed here)

- `getMe` via `file_get_contents` without error handling (kept, made
  defensive once, in the shared helper).
- String-interpolated SQL in these flows (repo-wide issue).
- `renew_error` codes: renewal passes `1` today; keep the numbering.
- `service_factors` wipe at flow start in both flows (duplicated, but
  cheap and per-flow; shared via `cleanup_flow_state` reuse is optional —
  builder's call, no behavior change either way).
