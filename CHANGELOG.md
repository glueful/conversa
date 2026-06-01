# Changelog

All notable changes to Conversa are documented here.

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
- Glueful Framework `>=1.49.0`: `auth_basic` passthrough in `Http\Client` (for Twilio Basic auth) and `whatsapp`
  added to `SendNotification::SUPPORTED_TYPES` (for async delivery).
