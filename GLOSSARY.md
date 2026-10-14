# ZZPanelBot

Telegram bot selling VPN-panel services (Marzban and compatibles): plans, orders, payments, and delivery.

## Language

**LookupCache**:
Panels, categories, and plans read from MySQL once per request and re-queryable at any time.
_Avoid_: cache (bare), panel cache

**Memo**:
A short-TTL note of a remote check (Telegram membership, panel token validity) where minutes-old staleness is acceptable and expiry is the only invalidation.
_Avoid_: cache (bare), temp cache

**FlowState**:
Multi-step conversation data (buy, renewal, service list) that must be deleted when the flow finishes or cancels; TTL is only a backstop for abandoned flows.
_Avoid_: cache (bare), temp cache, temp file
