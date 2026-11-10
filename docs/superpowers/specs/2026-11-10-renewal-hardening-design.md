# Renewal Hardening — Money Path Safety

Date: 2026-11-10
Status: proposed (awaiting owner approval)
Review: `docs/reviews/2026-11-10-renewal-flow-review.md`
Related: `docs/superpowers/specs/2026-11-09-buy-renewal-dry-design.md` (later refactor)

## Intent & Why

Renewal is the only path in the bot that can take money and change a live
service, and it can end in two wrong states:

1. The panel is renewed, the user is not charged, and `orders` keeps the old
   expiry/volume/link, because success is judged on the reset call and the
   success message is sent before persistence.
2. A renewal is applied to the wrong panel, because the server step accepts any
   active panel while the order already records the hosting one.

The owner is hardening before an incident, not refactoring. The DRY refactor
(`complete_service()`) comes later and will adopt this ordering instead of
rediscovering it.

## Goals

- **One panel per renewal.** The panel comes from the order; the wizard offers
  only that panel; confirm re-checks and rejects a mismatch.
- **The `PUT` body is the source of truth.** Success means the panel accepted
  the update. The usage reset is best-effort and cannot turn a success into a
  failure.
- **Persist before deliver.** `orders` + coin are written in one transaction
  under a row lock, then the message is sent. Delivery failure is logged and
  does not roll back.
- **No silent state.** Every wizard step and confirm validates its input and
  the FlowState; a missing key produces a message, not nulls.
- **Back and cancel work.** `🔙 بازگشت` moves back one step; cancel cleans every
  file the flow wrote.
- **One confirm per tap.** A duplicate "☐ ایجاد سرویس" cannot renew or charge
  twice.
- **The money path is observable.** Structured `Logger` records for every
  terminal outcome.
- **The money path is tested.** Unit tests for confirm branches and for the
  response parser, using stubs only.

## Non-Goals

- No change to renewal semantics: expiry is reset to `now + plan days` and
  usage is wiped. Extending from the current expiry is a product decision
  (review finding #7) and is not part of this pass.
- No buy-flow change, no shared `complete_service()`, no buying-side tests.
- No repo-wide SQL sweep. Only queries in the touched renewal path move to
  `db_row`/`db_exec`.
- No sanayi renewal. Sanayi orders are rejected explicitly.
- No step-name, step-order, keyboard-style, or FlowState-format change; no
  schema change; no new dependency.
- No copen/discount support in renewal.

## Anti-Goals

- A user mid-purchase or mid-renewal sees a changed screen or gets stuck after
  deploy.
- Coin is deducted when the panel update failed, or the panel is updated
  without a recorded charge and a visible order update.
- A renewal is recorded against a panel that does not host the service.
- Deploy requires APCu or a new table; an APCu-less host must still renew
  (only the duplicate-tap guard degrades).
- The patch grows into the DRY refactor: no buy code, no wizard rewrite.

## Constraints

- PHP 7.4 runtime (`php:7.4-cli`), mysqli, no new extensions.
- `db_row`/`db_exec` enforce placeholder counts and return `null`/`false` on
  driver errors; contract violations throw `InvalidArgumentException`
  (`AGENTS.md`).
- User-facing strings stay in `App/texts.json`; code never hardcodes Persian
  copy (existing exceptions like `"Not implanted Error"` are left as-is).
- FlowState: `renewal-service-<id>.json` through `read_renewal_json()` /
  `write_renewal_json()`; format is additive only.
- One Telegram update per request, 120 s request cap (`index.php:37`).
- Default `LOG_LEVEL` is `WARNING`, so `Logger::info()` is opt-in; ERROR and
  WARNING are the guaranteed trail.
- `marzban_renewal_api()` has exactly one caller today (`custom.php:423`);
  changing its return contract is internal.

## Approaches Considered

- **A — Hardening only, in place (chosen).** Behavior changes to the renewal
  money path plus tests, shipped on top of today's structure. Smallest review
  surface; the DRY refactor later lifts `renewal_confirm()`'s ordering into
  `complete_service()`.
  Trade-off: delivery logic stays duplicated until the refactor.
- **B — Amend the DRY plan, build `complete_service()` first.** One path for
  buy and renew, but the current DRY spec keeps message → orders → coins and
  validates the reset body, so it must be amended first; buy enters the blast
  radius and the safety fix ships later. Rejected: delays the money-path fix
  for a maintainability goal.
- **C — Extract a thin money-path core now, buy later.** One shared delivery
  sooner, but it is a refactor inside a hardening pass: bigger surface, and it
  still needs A's behavior changes. Rejected for this pass.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Approach | A | Soonest safe deploy, smallest surface |
| Renewal semantics | Unchanged (reset to `now + days`, wipe usage) | Product call, not a safety fix |
| Reset call | Best-effort, after a successful `PUT`; warning on failure | The `PUT` is what the user paid for |
| Panel choice | Only the order's panel is offered; confirm requires an exact match | Stops cross-panel renewals |
| Ordering | `PUT` → reset → transaction (`orders`, coin) → cleanup → message | Never announce an unrecorded renewal |
| Duplicate taps | APCu `apcu_add` lock, 120 s, released in `finally` | Atomic, no schema change, APCu is in the image |
| Lock without APCu | Skip the lock, log once, keep the flow | Fail-open: a host without APCu must still renew |
| API contract | `array{ok, body, error}`; pure parser helper | Testable without network |
| Code location | New `App/functions/renewal_confirm.php`, no side effects | Unit-testable without `custom.php` bootstrap |
| Wording | `success_renew_service` if present, else purchase key | Works before and after the DRY text change |
| Persistence failure after `PUT` | New text key `renew_persist_failed` with the service code | Do not tell the user "renewal failed" when it applied |
| Accepted-but-incomplete `PUT` body | Treat as renewed: charge, persist, deliver without sub URL | Owner decision 2026-11-10; unreachable on current Marzban (verified) |

## Design

### Surface

| File | Change |
|---|---|
| `custom.php` | Wizard guards at server/plan steps, real back navigation, cancel cleanup, confirm branch delegates to `renewal_confirm()`. |
| `App/functions/renewal_confirm.php` (new) | `renewal_confirm()`, `renewal_state_missing()`, `renewal_subscribe_url()`, `renewal_links_text()`. No top-level side effects, no `require` of its own. |
| `App/apis/marzban-api/api.php` | `marzban_renewal_api()` validates arguments, returns the result array, no reset, no `sleep`. New `marzban_reset_user_usage()`. New pure `marzban_parse_api_result()`. |
| `App/Utils/apcu_tools.php` | `apcu_safe_add($key, $value, $ttl)` with a tri-state result. |
| `App/texts.json` | One new key, `renew_persist_failed`. |
| `docs/cache-system.md` | Note the renewal lock key and that the reset no longer lives in the renewal API function. |

`custom.php` gets `require_once __DIR__ . "/App/functions/renewal_confirm.php";`
next to its other `require_once` lines. `renewal_confirm.php` resolves
`marzban_renewal_api`, `marzban_reset_user_usage`, `db_row`, `db_exec`,
`sendMessage`, `step`, `read_renewal_json`, `delete_temp_file`, `getMe`, and
`Logger` at call time; tests define stubs before including it.

### Wizard guards

Step names and order stay exactly as today:
`renewal_service_get_service_name` → `renewal_service_choose_server` →
`renewal_service_select_plan` → `renewal_service_confirm_service`.

| Step | Guard | On failure |
|---|---|---|
| get_service_name | `trim((string)$text)` non-empty; order exists for `code` + `from_id`; `orders.type` is `marzban` | Re-prompt; unknown code re-prompts; non-marzban order ends the flow with `renew_error` (2) |
| choose_server | FlowState has `old_server`; `$text` equals it | Back button returns to name step (`exit`); any other value shows `choice_error` + the same one-panel keyboard |
| select_plan | FlowState has `old_server`, `new_server`, `config_name`; plan row active; `coin >= price` | Back button returns to server step; unknown plan shows `choice_error` and stays on the step |
| confirm | Delegated to `renewal_confirm()` | See failure map |

The server keyboard offers the order's panel only, plus `🔙 بازگشت`. No
`get_active_panels()` list, no `service_factors` writes or deletes.

### `renewal_confirm()` order

1. `step('none')`, send `create_service_proccess`.
2. Validate FlowState keys (`config_name`, `config_base_name`, `new_plan`,
   `new_plan_price`, `new_server`) with `renewal_state_missing()`; validate the
   price is numeric and `>= 0`.
3. Load the order by `code = config_base_name AND from_id`; require
   `type = marzban`; require `new_server === order.location`.
4. Load the plan (`limit`, `date`), the panel by `order.location`, require
   `type = marzban`.
5. Check `$user['coin'] >= price`; acquire the panel token.
6. `marzban_renewal_api()`; require `ok` (the `PUT` was accepted). If an
   accepted body lacks `links`/`subscription_url` (owner decision: unreachable
   in practice), log a WARNING and continue with empty delivery — no
   subscription URL in the message, `orders.link` empty.
7. `marzban_reset_user_usage()`; failure logs a warning and continues.
8. Build `links` and the subscribe URL; open a transaction:
   `SELECT coin ... FOR UPDATE` → `UPDATE orders` → `UPDATE users` → commit.
   Roll back and stop on any failure.
9. Delete `renewal-service-<id>.json`, log success.
10. Resolve the bot username (`getMe()`, caught), build the caption/body,
    `sendSuccessResponse()` (caught).

Failure map:

| Failure | User sees | Level |
|---|---|---|
| Missing/invalid state, bad price | `renewal_service_config_not_found` / `renew_error` 1 | WARNING / ERROR |
| Order missing, non-marzban, panel mismatch | `renew_error` 2 | ERROR |
| Plan/panel missing, token failed | `renew_error` 0/2, token text | ERROR |
| `PUT` not accepted (curl error, non-user body, `detail`) | `renew_error` 1 | ERROR |
| Accepted body missing `links`/`subscription_url` | delivered without the sub URL; hint in message | WARNING |
| Reset failed | nothing extra | WARNING |
| Persistence failed after `PUT` | `renew_persist_failed` with the code | ERROR |
| Delivery failed after commit | `qr_unavailable_hint` | ERROR |

Nothing throws out of `renewal_confirm()`; the global catch is not part of the
flow. Note the persistence-failure case is the one where money is *not* taken
and the service *is* renewed: the new text tells the user to contact support
with the code rather than claiming the renewal failed.

### API contract

```php
marzban_renewal_api($username, $traffic_limit, $expire_at, $token, $url): array
// array{ok: bool, body: array|null, error: string|null}
```

- Throws `InvalidArgumentException` when `username`, `token`, or `url` is not a
  non-empty string (caller bug, before any network call).
- On curl failure: `ok=false`, `error=curl_error()`.
- On non-JSON or a body without `username`: `ok=false`, `error` = `detail` when
  present, else `invalid response`.
- On success: `ok=true`, `body` = decoded `PUT` response.
- `sleep(1.5)` is removed; there is nothing to wait for once the reset is a
  separate best-effort call.

`marzban_parse_api_result($raw, $curl_error = null)` is the pure body of the
above (raw string → result array), so tests need no server. Both
`marzban_renewal_api()` and `marzban_reset_user_usage()` use it; reset also
treats a body containing `detail` as a failure.

### Duplicate-tap lock

```php
$lock = apcu_safe_add('renewal_lock_' . $from_id, 1, 120); // true | false | null
if ($lock === false) { sendMessage($from_id, $texts['wait']); return; }
try { ... } finally { if ($lock === true) apcu_safe_del('renewal_lock_' . $from_id); }
```

`apcu_safe_add` returns `true` when added, `false` when the key exists, and
`null` when ext-apcu is missing (fail-open, warn once through
`apcu_warn_once()`). TTL 120 s covers the request cap; the key is also released
on every exit path. Without APCu the double-tap window remains, which is
accepted: the image installs APCu (`Dockerfile:17`, `install.sh:212`), and a
worker without it has no shared memory to guard with anyway.

### Transaction and engine

`SELECT ... FOR UPDATE` only locks rows on InnoDB. The design assumes
`orders`/`users` are InnoDB (the repo's migrations create InnoDB); the plan's
verification step confirms it with `SHOW TABLE STATUS` before deploy. If a
table is MyISAM the lock is a no-op and the flow still works, with the old
check-then-act risk.

### Logging

All records carry `from_id`; money-path records also carry `code`,
`location`, `plan`, `price` where known.

| Event | Level |
|---|---|
| `renewal_completed` | INFO |
| `renewal_cancelled` | INFO |
| `renewal_state_missing` | WARNING |
| `renewal_reset_failed` | WARNING |
| `renewal_getme_failed`, `renewal_delivery_failed` | WARNING / ERROR |
| `renewal_order_missing`, `renewal_not_marzban`, `renewal_panel_mismatch`, `renewal_plan_missing`, `renewal_panel_missing`, `renewal_token_failed`, `renewal_api_failed`, `renewal_api_incomplete`, `renewal_persist_failed` | ERROR |

## Builder check (2026-11-10)

A fresh subagent reviewed the design against the code. It found the seam
"what does the Marzban `PUT` body actually contain" — the repo never observed
it (today's code reads the fields from the reset response after a `sleep`).

**Verified against the Marzban source (`app/models/user.py`):** `PUT
/api/user/{username}` returns `UserResponse`, and its model validators
(`validate_links`, `validate_subscription_url`) guarantee `links` and
`subscription_url` are populated on every response. The router also commits
the DB row before responding. So:

- `ok = username in body` ⇒ the update was applied.
- An accepted body missing `links`/`subscription_url` is effectively
  unreachable on current Marzban; the branch is a defensive guard.

**Owner decision (2026-11-10):** if the `PUT` is accepted but the body is
missing `links`/`subscription_url`, the bot treats it as renewed — charge,
persist, and deliver without the subscription URL (existing
`qr_unavailable_hint` points the user to «🎯 سرویس های من»). The
`renew_error 1` path is reserved for `PUT` calls that were not accepted.

## Testing

Unit tests (zero-dependency, auto-discovered by `tests/unit/run.sh`):

- `tests/unit/marzban_api_result_test.php` — parser: curl error, non-JSON,
  `detail` body, body without `username`, valid body; `marzban_renewal_api()`
  argument violations throw before any curl call; reset treats `detail` as
  failure.
- `tests/unit/apcu_safe_add_test.php` — tri-state contract; without ext-apcu
  returns `null` and does not fatal (runs in the plain `php` CLI image).
- `tests/unit/renewal_confirm_test.php` — stubs for `db_row`, `db_exec`,
  `sendMessage`, `step`, `read_renewal_json`, `delete_temp_file`,
  `marzban_renewal_api`, `marzban_reset_user_usage`, `getMe`,
  `sendSuccessResponse`, `Logger`; fake mysqli with `begin_transaction`/
  `commit`/`rollback`. Cases: missing state, bad price, order missing,
  non-marzban order, panel mismatch, plan missing, panel missing, non-marzban
  panel, coin short, token failure, API failure, incomplete body, reset failure
  (delivered anyway, warning logged), persistence failure (rollback, no
  message, `renew_persist_failed`), success (message sent after commit, exact
  `orders` params, cleanup).
- `tests/unit/renewal_helpers_test.php` — `renewal_state_missing()`,
  `renewal_links_text()`, `renewal_subscribe_url()` (absolute vs relative).

Wizard guards (back/cancel/validation) are not unit-tested: `custom.php`
executes top-level bootstrap code on include. They are covered by the smoke
matrix below, which the plan spells out step by step.

## Rollout & Verification

- No migration; step names and FlowState format unchanged, so in-flight users
  are unaffected. New FlowState keys are additive.
- Deploy order: `api.php` and `renewal_confirm.php` may ship with `custom.php`
  in one release; there is no state where the old confirm calls the new API
  contract.
- Verification: `php -l` on all touched files; unit suite; integration suite;
  smoke: renew success, renew with panel down (no charge, no order change),
  reset down (renewed + charged + warning), cancel at each step, back at server
  and plan, duplicate confirm tap, buy flow unchanged, copen unchanged,
  `orders`/coin/`count_service` after renew, FlowState files gone, log records
  present at `LOG_LEVEL=INFO`.

## Left to the Builder

- Exact wording of `renew_persist_failed` (Persian, includes `%s` for code)
  and the message shown for a duplicate tap (`wait` is available).
- Test stub shape for `$sql` (a small class with `begin_transaction`,
  `commit`, `rollback`, and whatever `db_exec`/`db_row` stubs need).
- Whether `renewal_confirm.php` is required from `custom.php` or `index.php`
  (both are always loaded together; `custom.php` is the closer home).
- Commit split of the two files plus tests.
