# Telegram 429 Retry Handling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Telegram HTTP 429 rate limit backoff in `sendSuccessResponse()` with a 3-second default delay, 5-second maximum cap, and text fallback when delays exceed safe thresholds.

**Architecture:** Update `sendSuccessResponse()` in `App/apis/telegram-api/functions/responses.php` to detect `error_code === 429` in `bot('sendPhoto', ...)` responses. When detected, extract `parameters.retry_after` (default 3s), sleep if within the 5s ceiling and attempts remain, or abort to text fallback if the delay exceeds 5s. An optional sleeper callable parameter ensures deterministic, zero-latency unit tests.

**Tech Stack:** PHP 7.4, zero-dependency unit tests (`tests/unit/run.sh`), Docker (`php:7.4-cli`).

**Spec:** `docs/superpowers/specs/2026-10-30-telegram-429-retry-handling-design.md`

## Global Constraints

- PHP 7.4 compatibility.
- Zero external runtime dependencies.
- Retain existing `Logger::warning` and `Logger::error` call conventions.
- Default `retry_after` must be 3 seconds if missing or non-positive.
- Max wait threshold per retry is 5 seconds; exceeding it aborts retries immediately to text fallback.
- Non-429 errors must not sleep.

## Review Focus

1. `parameters.retry_after` missing on 429 response: defaults to 3 seconds wait time.
2. `parameters.retry_after` non-positive/non-numeric on 429 response (e.g. 0, -1, "abc"): defaults to 3 seconds wait time.
3. `parameters.retry_after` exceeds 5s (e.g. 6s): does not sleep; immediately aborts retry loop and falls back to text `sendMessage()`.
4. Final retry attempt fails with 429: does not sleep before falling back to text.
5. Non-429 error (e.g. 400 bad request or curl exception): retries immediately without calling sleeper.

---

### Task 1: 429 Retry Backoff and Fallback in `sendSuccessResponse()`

**Files:**
- Create: `tests/unit/send_success_response_test.php`
- Modify: `App/apis/telegram-api/functions/responses.php:2-38`

**Interfaces:**
- Consumes:
  - `bot(string $method, array $data)` -> returns array or throws Exception
  - `sendMessage(int|string $chat_id, string $text, array|null $keyboard)`
  - `Logger::warning(string $msg, array $context)`
  - `Logger::error(string $msg, array $context)`
- Produces:
  - `sendSuccessResponse($from_id, $qr_code_status, $qr_data, $caption_text, $message_text, $start_key, $retry_count = 3, $sleeper = 'sleep')`

- [ ] **Step 1: Write failing unit test in `tests/unit/send_success_response_test.php`**

Write a test file covering:
- First attempt success (returns reply, sleeper not called).
- 429 with explicit `retry_after = 2` sleeping 2s and succeeding on 2nd attempt.
- 429 with missing `parameters.retry_after` defaulting to 3s sleep.
- 429 with non-positive `retry_after = 0` defaulting to 3s sleep.
- 429 with `retry_after = 6` (> 5s) immediately aborting to fallback without calling sleeper.
- 429 on final retry not sleeping after the last attempt.
- Non-429 error retrying without calling sleeper.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker build -q -t swift-gecko-tests -f tests/integration/test.Dockerfile . && docker run --rm swift-gecko-tests php tests/unit/send_success_response_test.php`
Expected: FAIL (sleeper called 0 times or 429 not handled).

- [ ] **Step 3: Implement 429 backoff handling in `App/apis/telegram-api/functions/responses.php`**

Update `sendSuccessResponse()` signature:
```php
function sendSuccessResponse($from_id, $qr_code_status, $qr_data, $caption_text, $message_text, $start_key, $retry_count = 3, $sleeper = 'sleep')
```

In the photo sending retry loop:
- Check if `isset($bot_reply['error_code']) && $bot_reply['error_code'] === 429`.
- Parse `retry_after`:
  ```php
  $retry_after = (isset($bot_reply['parameters']['retry_after']) && is_numeric($bot_reply['parameters']['retry_after']) && (int)$bot_reply['parameters']['retry_after'] > 0)
      ? (int)$bot_reply['parameters']['retry_after']
      : 3;
  ```
- If `$retry_after > 5`:
  `Logger::warning('sendPhoto 429 retry_after exceeds threshold, aborting retries', array('chat_id' => $from_id, 'retry_after' => $retry_after));`
  `break;`
- Log warning:
  `Logger::warning('sendPhoto 429 rate limited, waiting to retry', array('chat_id' => $from_id, 'attempt' => $i + 1, 'retry_after' => $retry_after));`
- If `$i + 1 < $retry_count`:
  Invoke `is_callable($sleeper) ? call_user_func($sleeper, $retry_after) : null;`
- Continue next iteration.

- [ ] **Step 4: Run unit tests to verify they pass**

Run: `docker build -q -t swift-gecko-tests -f tests/integration/test.Dockerfile . && docker run --rm swift-gecko-tests bash tests/unit/run.sh`
Expected: `send_success_response_test.php` passes and all 12 unit tests PASS.

- [ ] **Step 5: Run regression checks**

Run: `docker run --rm swift-gecko-tests php tools/logger_selfcheck.php`
Expected: `ALL CHECKS PASSED`.

- [ ] **Step 6: Commit**

```bash
git add App/apis/telegram-api/functions/responses.php tests/unit/send_success_response_test.php docs/superpowers/plans/2026-10-30-telegram-429-retry-handling.md
git commit -m "feat: handle telegram 429 rate limit backoff in sendSuccessResponse"
```
