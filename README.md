# Conversa (SMS & WhatsApp) for Glueful

> **Status: In development.** This README documents the design and the scope of
> the first release. Sections describing behavior are the **intended** contract;
> where something is not yet implemented it is called out as *Planned* or under
> [Roadmap](#roadmap). Treat this as the spec the extension is being built against.

## Overview

Conversa is Glueful's **phone-based messaging layer** — a unified API for sending
**SMS** and **WhatsApp** messages over swappable provider backends (Twilio, Meta
WhatsApp Cloud API, Africa's Talking, Vonage, …). Applications send OTPs, alerts,
reminders, order updates, and customer messages without caring which provider is
wired up underneath.

It completes Glueful's multi-channel notification story:

| Extension | Channel | Handles |
| --------- | ------- | ------- |
| `glueful/email-notification` | `email` | Transactional & notification email |
| `glueful/notiva` | `push` | Mobile & web push (FCM, APNs, Web Push) |
| **`glueful/conversa`** | **`sms`, `whatsapp`** | **Phone-based messaging** |

Like Notiva, Conversa registers its channels with Glueful's notification system,
so the same `Notifiable` / `NotificationDispatcher` flow that sends email and push
can also send SMS and WhatsApp — while a direct service API stays available for
OTP and verification use cases.

### Why phone messaging

- **Reach where email/push fall short.** In many markets (across Africa, LATAM,
  and mobile-first products generally) SMS and WhatsApp are far more reliable than
  email and are not as easily disabled as push.
- **Security workflows.** Phone verification, login codes, password-reset codes,
  and transaction confirmation all need a phone-based channel.
- **Provider independence.** Start on Twilio, later move SMS to Africa's Talking
  while keeping WhatsApp on Meta Cloud API — without touching application code.

## Scope of v1

The first version is deliberately **narrow** — a solid sending + tracking core
that later conversational features build on:

- ✅ **Send SMS** through a configured driver
- ✅ **Send WhatsApp** through a configured driver
- ✅ **Provider drivers** behind one interface (Twilio, WhatsApp Cloud, Africa's Talking, Vonage), selectable per channel via config
- ✅ **Delivery webhooks** — receive provider status callbacks and update message state
- ✅ **Message logging** — persist every send, its delivery state, provider response, and retries
- ✅ **OTP / verification helper** — send and verify short codes over SMS or WhatsApp
- ✅ **Notification channels** — `sms` and `whatsapp` registered with the framework's `ChannelManager`

Everything under [Roadmap](#roadmap) (inbound replies, conversation threads,
templates, campaigns, contact lists, opt-in management) is explicitly **out of
scope for v1**.

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

# Verify discovery and provider wiring
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

# OTP / verification defaults
CONVERSA_OTP_ENABLED=true
CONVERSA_OTP_CHANNEL=sms                # sms | whatsapp
CONVERSA_OTP_LENGTH=6
CONVERSA_OTP_TTL=300                    # seconds

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
and registering it; the public API is unchanged.

## Endpoints

Base prefix: `/conversa`. Application endpoints require auth and apply rate
limiting; webhook endpoints are public but signature/verify-token protected.

| Method & path | Purpose | v1 |
| ------------- | ------- | -- |
| `POST /conversa/messages` | Send an SMS or WhatsApp message | ✅ |
| `GET /conversa/messages` | Query the message log (status, recipient, date) | ✅ |
| `POST /conversa/otp/send` | Send a verification code | ✅ |
| `POST /conversa/otp/verify` | Verify a submitted code | ✅ |
| `GET /conversa/webhooks/{provider}` | Provider webhook handshake (e.g. Meta verify token) | ✅ |
| `POST /conversa/webhooks/{provider}` | Delivery-status (and, later, inbound) callbacks | ✅ |

```bash
API_BASE=http://localhost:8000
TOKEN="<YOUR_BEARER_TOKEN>"

# Send an SMS
curl -s -X POST "$API_BASE/conversa/messages" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "channel": "sms", "to": "+15551234567", "body": "Your code is 123456" }' | jq .

# Send a WhatsApp message
curl -s -X POST "$API_BASE/conversa/messages" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "channel": "whatsapp", "to": "+15551234567", "body": "Your order has shipped" }' | jq .

# Send + verify an OTP
curl -s -X POST "$API_BASE/conversa/otp/send" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "to": "+15551234567", "channel": "sms" }' | jq .

curl -s -X POST "$API_BASE/conversa/otp/verify" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "to": "+15551234567", "code": "123456" }' | jq .
```

## Usage (PHP)

### Via the notification system

Because Conversa registers `sms` and `whatsapp` channels, any `Notifiable` that
routes those channels can receive phone messages through the same dispatcher used
for email and push:

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

### Direct service (OTP / verification)

```php
use Glueful\Extensions\Conversa\Services\ConversaService;

$conversa = app($context, ConversaService::class);

// Fire-and-track a one-off message
$conversa->send(channel: 'whatsapp', to: '+15551234567', body: 'Welcome to Acme!');

// OTP flow
$conversa->sendOtp(to: '+15551234567', channel: 'sms');
$ok = $conversa->verifyOtp(to: '+15551234567', code: '123456'); // bool
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
- **Twilio / Vonage / Africa's Talking** post delivery-status callbacks to their
  respective `/conversa/webhooks/{provider}` paths; configure that URL in the
  provider dashboard. Signature verification is applied per provider where
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
- Do not log full message bodies or recipient numbers in production beyond what's needed for support; OTP codes must never be logged.
- Apply rate limits to OTP and send endpoints (included) to prevent abuse and runaway provider spend.
- Store phone numbers in E.164 and treat them as personal data.

## Metadata

- Package: `glueful/conversa` (`type: glueful-extension`)
- Provider: `Glueful\Extensions\Conversa\ConversaServiceProvider`
- Channels: `sms`, `whatsapp`
- Config: `config/conversa.php` · Env prefix: `CONVERSA_*`
- Migration: `conversa_messages`

## Troubleshooting

- **Extension not loading** — installing doesn't enable it; run `php glueful extensions:enable conversa`, then `php glueful extensions:diagnose`. In production, run `php glueful extensions:cache`.
- **Sends silently skipped** — the selected driver's credentials are missing; check `extensions:diagnose` and the logs (the driver logs and skips rather than crashing).
- **WhatsApp webhook 403 on setup** — `CONVERSA_WHATSAPP_VERIFY_TOKEN` must match the value entered in the Meta dashboard.
- **Delivery state stuck at `sent`** — the provider's status-callback URL isn't pointed at `/conversa/webhooks/{provider}`, or signature verification is rejecting it.
- **No message-log rows** — run migrations (`php glueful migrate run`) and ensure `CONVERSA_LOG_MESSAGES=true`.
