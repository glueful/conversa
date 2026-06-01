# Conversa (SMS & WhatsApp) for Glueful

> **🚀 Version 0.1.0** — `sms`/`whatsapp` notification channels with swappable
> provider drivers (Twilio, Meta WhatsApp Cloud, log), a message log, delivery
> webhooks, idempotent direct sends, WhatsApp template send, and message-lifecycle
> events. Pre-release: the API is stable but not yet battle-tested in production.

## Overview

Conversa is Glueful's **phone-based messaging layer** — it registers `sms` and
`whatsapp` notification channels and sends through swappable provider backends
(Twilio and Meta WhatsApp Cloud API, with more to come). Applications send
alerts, reminders, order updates, and customer messages without caring which
provider is wired up underneath.

It completes Glueful's multi-channel notification story:

| Extension | Channel(s) | Handles |
| --------- | ---------- | ------- |
| `glueful/email-notification` | `email` | Transactional & notification email |
| `glueful/notiva` | `push` | Mobile & web push (FCM, APNs, Web Push) |
| **`glueful/conversa`** | **`sms`, `whatsapp`** | **Phone-based messaging** |

**Conversa provides the delivery *means*, not workflow logic.** Just as
`email-notification` registers an `email` channel for the framework's notification
system to use, Conversa registers `sms`/`whatsapp` channels — your application code
decides *what* to send and *when*; Conversa delivers it and tracks the result
(see [Notification-system integration](#notification-system-integration)).

### Why phone messaging

- **Reach where email/push fall short.** In many markets (across Africa, LATAM,
  and mobile-first products generally) SMS and WhatsApp are far more reliable than
  email and are not as easily disabled as push.
- **Transactional & high-priority alerts.** Account and transaction confirmations
  and other time-sensitive notifications often need a phone-based delivery channel —
  Conversa is that channel; your application drives the workflow.
- **Provider independence.** Run both channels on Twilio (it serves SMS *and*
  WhatsApp), or keep WhatsApp on Meta Cloud API while Twilio handles SMS — and add
  more providers later behind the same interface, without touching application code.

## Features

- ✅ **`sms` and `whatsapp` notification channels** registered with the framework's `ChannelManager` (the same contract `email-notification`'s `EmailChannel` implements)
- ✅ **Send SMS** through a configured driver
- ✅ **Send WhatsApp** through a configured driver — free text or **approved templates**
- ✅ **Provider drivers** behind one interface (Twilio, WhatsApp Cloud, log), selectable per channel via config
- ✅ **WhatsApp templates** — a logical template name maps to each driver's identity (Twilio `ContentSid`, Meta name + language) via `templates` config
- ✅ **Idempotent direct sends** — caller-supplied `Idempotency-Key` collapses retries to one message
- ✅ **Delivery webhooks** — receive provider status callbacks (fail-closed signature verification) and update message state
- ✅ **Message logging** — persist every send, its delivery state, provider response, and retries
- ✅ **Lifecycle events** — `MessageSent`, `MessageDelivered`, `MessageFailed`

Conversa is the delivery channel, not the workflow. Conversational features
(inbound replies, threads, campaigns, contact lists, opt-in management) are tracked
on the [Roadmap](#roadmap).

## Requirements

- PHP 8.3+
- Glueful Framework 1.49.0+
- A configured messaging provider account (Twilio or Meta WhatsApp Cloud API)
- Outbound HTTPS access to the provider's API (calls go through Glueful's HTTP client)

## Installation

```bash
composer require glueful/conversa

# Enable it — installing does not auto-load an extension; this adds the provider
# to config/extensions.php's `enabled` list and recompiles the cache.
php glueful extensions:enable conversa

# Create the message-log table
php glueful migrate run

# Verify discovery and channel registration
php glueful extensions:list
php glueful extensions:info conversa
php glueful extensions:diagnose
```

In production, manage the `enabled` list in config and run
`php glueful extensions:cache` in your deploy step (production boots only from the
compiled manifest).

## Configuration

Conversa is configured via `config/conversa.php` (merged from the extension) and
environment variables. Each channel selects a **default driver**; you can run SMS
and WhatsApp on different providers.

```env
# Channel → driver selection (default: log — writes to the log instead of a provider,
# so the extension is safe to enable before any credentials are configured)
CONVERSA_SMS_DRIVER=twilio              # twilio | log
CONVERSA_WHATSAPP_DRIVER=whatsapp_cloud # whatsapp_cloud | twilio | log

# Message logging / retries
CONVERSA_LOG_MESSAGES=true
CONVERSA_MAX_RETRIES=3

# Privacy (both default true)
CONVERSA_STORE_BODY=true                # persist the message body/template vars in the log
CONVERSA_REDACT_PROVIDER_RESPONSE=true  # redact recipient/body fields from the stored provider response

# Public base URL used to rebuild the callback URL Twilio signed when behind a proxy/LB
CONVERSA_WEBHOOK_BASE_URL=https://api.example.com
```

### Provider credentials

```env
# Twilio (SMS and/or WhatsApp)
CONVERSA_TWILIO_SID=ACxxxxxxxx
CONVERSA_TWILIO_TOKEN=xxxxxxxx
CONVERSA_TWILIO_SMS_FROM=+15551234567
CONVERSA_TWILIO_WHATSAPP_FROM=whatsapp:+15551234567

# Meta WhatsApp Cloud API
CONVERSA_WHATSAPP_PHONE_ID=1234567890
CONVERSA_WHATSAPP_TOKEN=EAAxxxxxxxx
CONVERSA_WHATSAPP_VERIFY_TOKEN=your-webhook-verify-token   # GET webhook handshake
CONVERSA_WHATSAPP_APP_SECRET=xxxxxxxx                      # webhook signature check
```

Only the drivers you actually use need credentials. When the driver selected for a
channel is unavailable (missing credentials/sender), the send is **recorded as a
`failed` message** and a `MessageFailed` event is dispatched — the send path never
crashes, and you can see the failure in the message log.

## Provider drivers

Every provider implements one driver interface, so application code never depends
on a specific vendor. Conversa ships with:

| Driver key | Provider | SMS | WhatsApp |
| ---------- | -------- | --- | -------- |
| `twilio` | Twilio | ✅ | ✅ |
| `whatsapp_cloud` | Meta WhatsApp Cloud API | — | ✅ |
| `log` | Logs the message instead of sending (dev/test default) | ✅ | ✅ |

Driver availability is **channel-aware**: Twilio reports `whatsapp` as available
only when `CONVERSA_TWILIO_WHATSAPP_FROM` is set, and `sms` only when
`CONVERSA_TWILIO_SMS_FROM` is set — so a one-channel Twilio setup never advertises
the other channel.

More providers (e.g. Africa's Talking, Vonage) are [planned](#roadmap) behind the
same interface — see Roadmap.

Switching providers is a config change (`CONVERSA_SMS_DRIVER` / `CONVERSA_WHATSAPP_DRIVER`)
— no code changes. Adding a new provider means implementing the driver interface
and registering it; the channels and public API are unchanged.

## Notification-system integration

Conversa plugs into Glueful's notification system the same way `email-notification`
does. It implements `NotificationChannel` for `sms` and `whatsapp` and registers both
with the `ChannelManager` during boot, so anything that dispatches to those channels
is delivered by Conversa:

- `getChannelName()` returns the channel (`sms` / `whatsapp`).
- `send($notifiable, $data)` reads `routeNotificationFor('sms'|'whatsapp')` for the
  destination number and sends `$data['body']` (or `$data['template']`).

Any `NotificationDispatcher::send(..., ['sms'])` (or `['whatsapp']`) call — from your
own code or elsewhere in the framework — routes through Conversa, which sends via the
configured driver and records the result.

## Endpoints

Base prefix: `/conversa`. Application endpoints require auth and apply rate
limiting; webhook endpoints are public but signature/verify-token protected.

| Method & path | Purpose |
| ------------- | ------- |
| `POST /conversa/messages` | Send an SMS or WhatsApp message directly (honours an `Idempotency-Key` header) |
| `GET /conversa/messages` | Query the message log, filterable by `status` / `channel` / `to`, paginated via `page` / `per_page` |
| `GET /conversa/webhooks/{provider}` | Provider webhook handshake (e.g. Meta verify token) |
| `POST /conversa/webhooks/{provider}` | Provider delivery-status callbacks |

```bash
API_BASE=http://localhost:8000
TOKEN="<YOUR_BEARER_TOKEN>"

# Send an SMS directly
curl -s -X POST "$API_BASE/conversa/messages" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "channel": "sms", "to": "+15551234567", "body": "Your order has shipped" }' | jq .

# Send a WhatsApp message directly
curl -s -X POST "$API_BASE/conversa/messages" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "channel": "whatsapp", "to": "+15551234567", "body": "Welcome to Acme!" }' | jq .

# Send a WhatsApp template (mapped to a provider template via `templates` config)
curl -s -X POST "$API_BASE/conversa/messages" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "channel": "whatsapp", "to": "+15551234567",
        "template": { "name": "order_shipped", "variables": ["1Z999"] } }' | jq .

# Idempotent send — repeating the same key returns the original message, no second send
curl -s -X POST "$API_BASE/conversa/messages" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: order-4711-shipped" \
  -d '{ "channel": "sms", "to": "+15551234567", "body": "Your order has shipped" }' | jq .

# Query the message log (filter + paginate)
curl -s "$API_BASE/conversa/messages?status=delivered&channel=sms&per_page=25&page=1" \
  -H "Authorization: Bearer $TOKEN" | jq .
```

## Usage (PHP)

### Via the notification system (preferred)

Because Conversa registers `sms` and `whatsapp` channels, any `Notifiable` that
routes those channels receives phone messages through the same dispatcher used for
email and push:

```php
use Glueful\Notifications\Services\ChannelManager;

$sms = container()->get(ChannelManager::class)->getChannel('sms');
$sms->send($user, ['body' => 'Your appointment is tomorrow at 9am']);
```

```php
use Glueful\Notifications\Contracts\Notifiable;

class User implements Notifiable
{
    public function routeNotificationFor(string $channel)
    {
        return match ($channel) {
            'sms', 'whatsapp' => $this->phone, // E.164, e.g. +15551234567
            default => null,
        };
    }
    // getNotifiableId(), shouldReceiveNotification(), getNotificationPreferences() …
}
```

### Direct send (one-off messages)

`ConversaService::send()` takes the channel, recipient, a payload array (exactly one
of `body` or `template`), and optional `opts` (`idempotency_key`, `from`, `meta`). It
returns a `DriverResult` (`->ok`, `->providerMessageId`, `->error`).

```php
use Glueful\Extensions\Conversa\ConversaService;

$conversa = app($context, ConversaService::class);

// Free-text SMS / WhatsApp
$conversa->send('whatsapp', '+15551234567', ['body' => 'Welcome to Acme!']);

// WhatsApp template (resolved to a provider template via `templates` config)
$conversa->send('whatsapp', '+15551234567', [
    'template' => ['name' => 'order_shipped', 'variables' => ['1Z999']],
]);

// Idempotent send — a repeat key returns the original message without sending again
$result = $conversa->send('sms', '+15551234567', ['body' => 'Your order has shipped'], [
    'idempotency_key' => 'order-4711-shipped',
]);
// $result->ok, $result->providerMessageId, $result->error
```

## Delivery tracking

Every send is recorded in a `conversa_messages` table (created by the extension's
migration), capturing at least:

- recipient, channel, driver, and the message body/template reference
- lifecycle state: `queued → sent → delivered` (or `failed` / `undelivered`)
- the provider's message ID and raw response
- retry count and last error

Delivery webhooks (`POST /conversa/webhooks/{provider}`) update the stored state
as providers report `sent` / `delivered` / `failed`, giving you an auditable
record for support and reconciliation. The message-log API (`GET /conversa/messages`)
queries this table.

## Webhooks

- **Meta WhatsApp Cloud** performs a `GET` handshake against
  `/conversa/webhooks/whatsapp_cloud` using `CONVERSA_WHATSAPP_VERIFY_TOKEN`, and
  signs `POST` callbacks with `CONVERSA_WHATSAPP_APP_SECRET` (verified before
  processing).
- **Twilio** posts delivery-status callbacks for **both SMS and WhatsApp** to
  `/conversa/webhooks/twilio`; configure that URL in the Twilio console. Signature
  verification is applied where the provider supports it.

Point each provider's status-callback / webhook URL at the matching path and
Conversa reconciles delivery state automatically.

## Roadmap

Conversa today is the sending + tracking core. Planned follow-ups:

- **Two-way messaging** — inbound message webhooks, replies, and conversation threads (WhatsApp especially is bidirectional).
- **Campaigns & broadcasts** — contact lists, batch sends, scheduling.
- **Preferences & compliance** — opt-in/opt-out management, per-recipient channel preferences, quiet hours.
- **More drivers** — additional providers behind the same interface (e.g. Africa's Talking, Vonage, and other regional gateways).

## Security considerations

- Treat provider tokens/secrets as secrets; never commit them and restrict who can read the `.env`.
- Verify webhook authenticity (Meta app-secret signature, provider signing where available) before trusting status updates.
- Do not log full message bodies or recipient numbers in production beyond what's needed for support; message bodies may carry sensitive content (see `CONVERSA_STORE_BODY` / `CONVERSA_REDACT_PROVIDER_RESPONSE`).
- Apply rate limits to send endpoints (included) to prevent abuse and runaway provider spend.
- Store phone numbers in E.164 and treat them as personal data.

## Metadata

- Package: `glueful/conversa` (`type: glueful-extension`)
- Provider: `Glueful\Extensions\Conversa\ConversaServiceProvider`
- Channels: `sms`, `whatsapp` (implement `NotificationChannel`, registered with `ChannelManager`)
- Events: `MessageSent`, `MessageDelivered`, `MessageFailed` (extend the framework `BaseEvent`)
- Config: `config/conversa.php` · Env prefix: `CONVERSA_*`
- Migration: `conversa_messages`

## Troubleshooting

- **Extension not loading** — installing doesn't enable it; run `php glueful extensions:enable conversa`, then `php glueful extensions:diagnose`. In production, run `php glueful extensions:cache`.
- **Channel not found when sending** — confirm the extension is enabled and the `sms`/`whatsapp` channels registered (`extensions:diagnose`); the framework looks them up by name via the `ChannelManager`.
- **Sends recorded as `failed` with `driver_unavailable`** — the selected driver's credentials/sender are missing; check `extensions:diagnose` and the logs. The send is logged as `failed` (and a `MessageFailed` event fires) rather than crashing.
- **WhatsApp webhook 403 on setup** — `CONVERSA_WHATSAPP_VERIFY_TOKEN` must match the value entered in the Meta dashboard.
- **Delivery state stuck at `sent`** — the provider's status-callback URL isn't pointed at `/conversa/webhooks/{provider}`, or signature verification is rejecting it.
- **No message-log rows** — run migrations (`php glueful migrate run`) and ensure `CONVERSA_LOG_MESSAGES=true`.
