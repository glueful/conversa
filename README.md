# Conversa (SMS & WhatsApp) for Glueful

> **Status: In development.** This README documents the design and the scope of
> the first release. Sections describing behavior are the **intended** contract;
> where something is not yet implemented it is called out as *Planned* or under
> [Roadmap](#roadmap). Treat this as the spec the extension is being built against.

## Overview

Conversa is Glueful's **phone-based messaging layer** — it registers `sms` and
`whatsapp` notification channels and sends through swappable provider backends
(Twilio, Meta WhatsApp Cloud API, Africa's Talking, Vonage, …). Applications send
OTPs, alerts, reminders, order updates, and customer messages without caring which
provider is wired up underneath.

It completes Glueful's multi-channel notification story:

| Extension | Channel(s) | Handles |
| --------- | ---------- | ------- |
| `glueful/email-notification` | `email` | Transactional & notification email |
| `glueful/notiva` | `push` | Mobile & web push (FCM, APNs, Web Push) |
| **`glueful/conversa`** | **`sms`, `whatsapp`** | **Phone-based messaging** |

**Conversa provides the delivery *means*, not workflow logic.** Exactly like
`email-notification` registers an `email` channel that the framework's notification
and verification machinery uses, Conversa registers `sms`/`whatsapp` channels. It
does **not** own OTP generation, code storage, or verification — that already lives
in the framework (see [Powering verification & notifications](#powering-verification--notifications)).

### Why phone messaging

- **Reach where email/push fall short.** In many markets (across Africa, LATAM,
  and mobile-first products generally) SMS and WhatsApp are far more reliable than
  email and are not as easily disabled as push.
- **Security workflows.** Phone verification, login codes, password-reset codes,
  and transaction confirmation need a phone-based delivery channel — Conversa is
  that channel; the framework drives the workflow.
- **Provider independence.** Run both channels on Twilio to start (it serves SMS
  *and* WhatsApp), then — say — move SMS to Africa's Talking while keeping WhatsApp
  on Twilio or Meta Cloud API, without touching application code.

## Scope of v1

The first version is deliberately **narrow** — a solid sending + tracking core
that later conversational features build on:

- ✅ **`sms` and `whatsapp` notification channels** registered with the framework's `ChannelManager` (the same contract `email-notification`'s `EmailChannel` implements)
- ✅ **Send SMS** through a configured driver
- ✅ **Send WhatsApp** through a configured driver
- ✅ **Provider drivers** behind one interface (Twilio, WhatsApp Cloud, Africa's Talking, Vonage), selectable per channel via config
- ✅ **Delivery webhooks** — receive provider status callbacks and update message state
- ✅ **Message logging** — persist every send, its delivery state, provider response, and retries

Everything under [Roadmap](#roadmap) (inbound replies, conversation threads,
templates, campaigns, contact lists, opt-in management) is explicitly **out of
scope for v1**. So is OTP/verification *logic* — Conversa is the channel those
flows deliver over, not the flow itself.

## Requirements

- PHP 8.3+
- Glueful Framework 1.48.0+
- A configured messaging provider account (Twilio, Meta WhatsApp Cloud API, Africa's Talking, or Vonage)
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
# Channel → driver selection
CONVERSA_SMS_DRIVER=twilio              # twilio | africastalking | vonage
CONVERSA_WHATSAPP_DRIVER=whatsapp_cloud # whatsapp_cloud | twilio

# Message logging / retries
CONVERSA_LOG_MESSAGES=true
CONVERSA_MAX_RETRIES=3
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

# Africa's Talking (SMS)
CONVERSA_AT_USERNAME=your-username
CONVERSA_AT_API_KEY=xxxxxxxx
CONVERSA_AT_FROM=YOURSENDERID

# Vonage (SMS)
CONVERSA_VONAGE_KEY=xxxxxxxx
CONVERSA_VONAGE_SECRET=xxxxxxxx
CONVERSA_VONAGE_FROM=YourBrand
```

Only the drivers you actually use need credentials. A driver whose configuration
is missing is skipped with a log entry rather than crashing the send path.

## Provider drivers

Every provider implements one driver interface, so application code never depends
on a specific vendor. v1 ships:

| Driver key | Provider | SMS | WhatsApp |
| ---------- | -------- | --- | -------- |
| `twilio` | Twilio | ✅ | ✅ |
| `whatsapp_cloud` | Meta WhatsApp Cloud API | — | ✅ |
| `africastalking` | Africa's Talking | ✅ | — |
| `vonage` | Vonage (Nexmo) | ✅ | — |

Switching providers is a config change (`CONVERSA_SMS_DRIVER` / `CONVERSA_WHATSAPP_DRIVER`)
— no code changes. Adding a new provider means implementing the driver interface
and registering it; the channels and public API are unchanged.

## Powering verification & notifications

Conversa follows the same pattern as `email-notification`. The framework's
verification machinery (`Glueful\Security\EmailVerification` and the
`NotificationService` / `NotificationDispatcher` it uses) **owns** code
generation, storage, expiry, and verification, and dispatches the message to a
**channel**. `email-notification` provides the `email` channel that
`POST /auth/verify-email` and `POST /auth/verify-otp` deliver over today.

Conversa provides the `sms` and `whatsapp` channels so the **same machinery** can
deliver codes and notifications over the phone. Conversa never sees the OTP logic
— it only sends what the channel is handed and reports delivery. Concretely:

- Conversa implements `NotificationChannel` for `sms` and `whatsapp`
  (`getChannelName()` returns the channel; `send($notifiable, $data)` reads
  `routeNotificationFor('sms'|'whatsapp')` for the destination number) and
  registers both with the `ChannelManager` during boot — mirroring
  `EmailNotificationProvider::register(ChannelManager)`.
- A verification or notification flow that targets the `sms`/`whatsapp` channel
  (e.g. a phone-verification endpoint, or any `NotificationDispatcher::send(..., ['sms'])`
  call) is then delivered by Conversa.

> The framework's current OTP verifier targets the `email` channel specifically.
> Delivering verification codes over SMS/WhatsApp is a framework-side change (point
> the verifier at the `sms`/`whatsapp` channel, or add a phone-verification flow) —
> Conversa's responsibility is purely to **make those channels available and send
> reliably**, not to reimplement OTP inside the extension.

## Endpoints

Base prefix: `/conversa`. Application endpoints require auth and apply rate
limiting; webhook endpoints are public but signature/verify-token protected.

| Method & path | Purpose | v1 |
| ------------- | ------- | -- |
| `POST /conversa/messages` | Send an SMS or WhatsApp message directly | ✅ |
| `GET /conversa/messages` | Query the message log (status, recipient, date) | ✅ |
| `GET /conversa/webhooks/{provider}` | Provider webhook handshake (e.g. Meta verify token) | ✅ |
| `POST /conversa/webhooks/{provider}` | Delivery-status (and, later, inbound) callbacks | ✅ |

Note there is **no** `/conversa/otp/*` endpoint — verification flows live in the
framework and use the `sms`/`whatsapp` channels, as described above.

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

# Query the message log
curl -s "$API_BASE/conversa/messages?status=delivered" \
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

```php
use Glueful\Extensions\Conversa\Services\ConversaService;

$conversa = app($context, ConversaService::class);
$conversa->send(channel: 'whatsapp', to: '+15551234567', body: 'Welcome to Acme!');
```

> The exact service method signatures are being finalized during implementation;
> this section reflects the intended shape and will be kept in sync with the code.

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
  `/conversa/webhooks/twilio`; **Vonage / Africa's Talking** post SMS status to
  their respective `/conversa/webhooks/{provider}` paths. Configure that URL in the
  provider dashboard; signature verification is applied per provider where
  supported.

Point each provider's status-callback / webhook URL at the matching path and
Conversa reconciles delivery state automatically.

## Roadmap

v1 is the sending + tracking core. Planned follow-ups (not in v1):

- **Two-way messaging** — inbound message webhooks, replies, and conversation threads (WhatsApp especially is bidirectional).
- **Templates** — provider-approved WhatsApp message templates and transactional SMS templates.
- **Campaigns & broadcasts** — contact lists, batch sends, scheduling.
- **Preferences & compliance** — opt-in/opt-out management, per-recipient channel preferences, quiet hours.
- **More drivers** — additional regional providers behind the same interface.

## Security considerations

- Treat provider tokens/secrets as secrets; never commit them and restrict who can read the `.env`.
- Verify webhook authenticity (Meta app-secret signature, provider signing where available) before trusting status updates.
- Do not log full message bodies or recipient numbers in production beyond what's needed for support; never log verification codes the channel is handed.
- Apply rate limits to send endpoints (included) to prevent abuse and runaway provider spend.
- Store phone numbers in E.164 and treat them as personal data.

## Metadata

- Package: `glueful/conversa` (`type: glueful-extension`)
- Provider: `Glueful\Extensions\Conversa\ConversaServiceProvider`
- Channels: `sms`, `whatsapp` (implement `NotificationChannel`, registered with `ChannelManager`)
- Config: `config/conversa.php` · Env prefix: `CONVERSA_*`
- Migration: `conversa_messages`

## Troubleshooting

- **Extension not loading** — installing doesn't enable it; run `php glueful extensions:enable conversa`, then `php glueful extensions:diagnose`. In production, run `php glueful extensions:cache`.
- **Channel not found when sending** — confirm the extension is enabled and the `sms`/`whatsapp` channels registered (`extensions:diagnose`); the framework looks them up by name via the `ChannelManager`.
- **Sends silently skipped** — the selected driver's credentials are missing; check `extensions:diagnose` and the logs (the driver logs and skips rather than crashing).
- **WhatsApp webhook 403 on setup** — `CONVERSA_WHATSAPP_VERIFY_TOKEN` must match the value entered in the Meta dashboard.
- **Delivery state stuck at `sent`** — the provider's status-callback URL isn't pointed at `/conversa/webhooks/{provider}`, or signature verification is rejecting it.
- **No message-log rows** — run migrations (`php glueful migrate run`) and ensure `CONVERSA_LOG_MESSAGES=true`.
