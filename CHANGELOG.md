# Changelog

All notable changes to Conversa are documented here.

## [Unreleased]

### Security
- Require explicit `conversa.messages.send` and `conversa.messages.read` permissions on the authenticated direct-send and message-log HTTP routes instead of allowing any authenticated user to send messages or read logged recipients/bodies.
- Validate outbound recipients as E.164 phone numbers before dispatching to provider drivers.

## [0.3.0] - 2026-06-06 — Notification Subsystem Refinement (Framework 1.51)

### Added
- **Structured channel results.** `AbstractConversaChannel` (and so `SmsChannel` / `WhatsAppChannel`) now implements `Glueful\Notifications\Contracts\RichNotificationChannel` and returns a `NotificationResult` from `sendNotification()`. It adapts the existing `DriverResult` — carrying the provider message id and raw provider response into the result metadata, plus send latency — and maps failures to stable error codes: `no_recipient` (no route for the channel → non-retryable) and `send_failed` (driver failure, surfacing the driver error → retryable). The framework dispatcher (1.51.0+) records these per channel; the legacy `send(): bool` contract is preserved by delegating to `sendNotification()`.

### Changed
- **Minimum framework requirement raised to `glueful/framework >=1.51.0`** (`require-dev` pinned to `^1.51.0`).
- **Channel registration migrated to the framework's extension helper.** `ConversaServiceProvider::boot()` now calls `registerNotificationChannel()` for the SMS + WhatsApp channels instead of reaching into the container by hand. This is now the **only** wiring path — framework 1.51.0 stopped hardcoding notification providers in its jobs, so a channel that isn't registered from `boot()` won't auto-wire into the shared dispatcher used by async dispatch/retries.

### Notes
- No change to the active send path, drivers, webhooks, or message storage — `sendNotification()` wraps the same `ConversaService::send()` call the bool `send()` already used.

## [0.2.0] - 2026-06-05 — Framework 1.50 Compatibility

### Changed
- **Minimum framework requirement raised to `glueful/framework >=1.50.1`** (`require-dev` pinned to `^1.50.1`); previously `>=1.49.1`.

### Notes
- Compatibility/maintenance release — **no code changes**. Conversa already uses the current framework APIs (events extend `Glueful\Events\Contracts\BaseEvent`; channels implement `Glueful\Notifications\Contracts\NotificationChannel`) and is fully decoupled from the user store — no `users` reference or foreign key; message recipients are tracked via the polymorphic `notifiable_type` / `notifiable_id` columns, so the framework's user-store extraction does not affect it. Verified against 1.50.1: 34 tests pass, PHPStan clean.

## [0.1.0] - 2026-06-01

### Added
- `sms` and `whatsapp` notification channels (registered with the framework `ChannelManager`).
- Provider drivers behind one interface: `twilio` (SMS + WhatsApp), `whatsapp_cloud` (WhatsApp), `log` (dev/test).
- WhatsApp template send (logical name → per-driver identity via `templates` config; Twilio `ContentSid`, Meta name+language).
- `ConversaService` send pipeline with caller-supplied idempotency and `retry()`.
- `conversa_messages` log + delivery-status webhooks (`/conversa/webhooks/{provider}`, fail-closed signature verification).
- Lifecycle events: `MessageSent`, `MessageDelivered`, `MessageFailed`.
- Privacy toggles: `store_body`, `redact_provider_response`.

### Requires
- Glueful Framework `>=1.49.1`:
  - `auth_basic` passthrough in `Http\Client` (for Twilio Basic auth) and `whatsapp`
    added to `SendNotification::SUPPORTED_TYPES` (for async delivery) — both since 1.49.0.
  - `QueryValidator` allows reserved SQL words as column names (1.49.1) — the
    `conversa_messages` table uses a `from` column.
