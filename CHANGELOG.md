# Changelog

All notable changes to Conversa are documented here.

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
