# Telegram 429 Retry Handling for Image Sending — Design

## Intent and why

`sendSuccessResponse()` in `App/apis/telegram-api/functions/responses.php` sends QR code images via `bot('sendPhoto', ...)`. When Telegram rejects the request with an error, the current loop immediately retries without delay.

When Telegram returns HTTP 429 (`error_code: 429`), retrying immediately with zero delay exhausts all retry attempts in milliseconds, exacerbates Telegram rate limits, and triggers an immediate text fallback while Telegram is still blocking requests.

This change adds 429-aware backoff to `sendSuccessResponse()`: it extracts `retry_after` from Telegram's response, waits before retrying when the delay is within safe webhook limits, and aborts immediately to text fallback if the requested delay is too high.

## Goals

- Detect Telegram 429 errors (`error_code === 429`) in `sendSuccessResponse()`.
- Extract `parameters.retry_after` from Telegram response, defaulting to 3 seconds if missing or non-positive.
- If `retry_after <= 5` seconds, wait (`sleep($retry_after)`) before executing the next retry attempt.
- If `retry_after > 5` seconds, abort image retries immediately and proceed to text fallback.
- Preserve existing zero-delay retry behavior for non-429 errors.
- Add zero-dependency unit tests verifying 429 handling, wait capping, and fallback behavior.

## Non-goals

- Modifying the generic `bot()` curl execution in `App/apis/telegram-api/utils/api_utils.php`.
- Adding background queue workers or asynchronous task queues.
- Exponential backoff or jitter algorithms.

## Anti-goals

- Long sleeps exceeding 5 seconds that risk PHP execution or reverse proxy timeouts.
- Silently swallowing 429 details without logging.

## Constraints

- PHP 7.4 compatibility.
- Minimal diff localized to `App/apis/telegram-api/functions/responses.php`.
- Standard logger integration via `Logger::warning()` and `Logger::error()`.

## Approaches

1. **Fixed Cap per Retry with 3s Default (Chosen)**
   - Check `$bot_reply['error_code'] === 429`.
   - Read `parameters.retry_after`, default to 3s if not provided or invalid.
   - If `retry_after <= 5`, sleep for that duration and retry.
   - If `retry_after > 5`, abort the retry loop and fall back to text.
   - *Trade-off:* Minimal lines of code, strictly bounds webhook latency, directly solves the problem.

2. **Cumulative Sleep Budget Across Retries**
   - Accumulate total sleep time across attempts against a global ceiling (e.g. 10s).
   - *Trade-off:* Extra state tracking without significant benefit given default `$retry_count` is 3 and single attempts are capped at 5s.

3. **Global Telegram 429 Handler inside `bot()`**
   - Intercept 429 inside `bot()` for all Telegram API calls.
   - *Trade-off:* High blast radius across the entire bot codebase; violates single-responsibility for this scoped task.

## What was decided

- Approach 1 is selected.
- Default `retry_after`: 3 seconds.
- Maximum wait threshold per retry: 5 seconds.
- Exceeding threshold triggers immediate abort to text fallback.
- Non-429 errors continue to retry immediately without sleep.

## What is left to the builder

- Implementation in `App/apis/telegram-api/functions/responses.php`.
- Adding unit test file in `tests/unit/send_success_response_test.php` verifying:
  - Successful sendPhoto on first attempt.
  - 429 with retry_after <= 5s triggers delay and succeeds on retry.
  - 429 with retry_after > 5s aborts loop immediately and falls back to text.
  - Non-429 error retries without delay.
