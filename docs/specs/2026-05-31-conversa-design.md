# Conversa Extension — Design Spec

**Date:** 2026-05-31
**Status:** Approved design — ready for implementation planning
**Target framework:** Glueful 1.49.0+

## Goal

Provide a phone-based messaging layer for Glueful that registers `sms` and
`whatsapp` notification channels and sends through swappable provider drivers
(Twilio, Meta WhatsApp Cloud API), with delivery-status webhooks and a persisted
message log. Conversa supplies the delivery **means**; it does not own
OTP/verification or any conversational workflow — those live in the framework and
consume Conversa's channels (the same way `email-notification` powers
`/auth/verify-email` via the `email` channel).

## Scope

**In scope (v1):**
- `sms` and `whatsapp` channels implementing `Glueful\Notifications\Contracts\NotificationChannel`, registered with `ChannelManager`.
- One send pipeline (`ConversaService`) used by both the notification system and a direct HTTP/service API.
- Provider drivers behind a single `ConversaDriver` interface: `twilio` (SMS + WhatsApp), `whatsapp_cloud` (WhatsApp), `log` (dev/test).
- Delivery-status webhooks that reconcile message state.
- A `conversa_messages` log (full audit, with privacy toggles).
- Synchronous send through `ConversaService`; async on the notification path via the framework's existing `SendNotification` job (no Conversa-specific queue/worker).
- Message-lifecycle domain events (`MessageSent`/`MessageDelivered`/`MessageFailed`).
- **Sending a pre-approved WhatsApp template** (a logical name + variables, resolved per-driver via a `templates` config map — Meta by name+language, Twilio by `ContentSid`) for business-initiated WhatsApp messages — the only way OTPs/alerts work on WhatsApp outside an open session. SMS and in-session WhatsApp use free-text bodies.
- **Caller-supplied idempotency** on the direct send API (dedupe paid external sends across retries/timeouts).

**Out of scope (roadmap, explicitly not v1):**
- Inbound messages, replies, conversation threads.
- Free-text variable interpolation / a template **registry or management UI** (v1 only *sends* an already-approved WhatsApp template by name; SMS bodies are raw text).
- Campaigns, broadcasts, scheduled/batch sends, contact lists, opt-in/preference management.
- A Conversa-specific queue or worker (async rides the framework's `SendNotification` job).
- Additional drivers (Africa's Talking, Vonage, etc.).
- SMS via any provider other than Twilio in v1.

> **WhatsApp messaging windows.** Business-initiated WhatsApp messages (OTPs,
> alerts, reminders, order updates) require a pre-approved **template** outside the
> 24-hour customer-service window — this is a WhatsApp platform rule and applies to
> **both** the Twilio and Meta Cloud drivers (Twilio rides Meta's WhatsApp infra).
> v1 therefore supports template send; free-text WhatsApp is only valid inside an
> open session. SMS has no such constraint.

## Architecture

Conversa follows Glueful's layered pattern: **thin adapters at the edges → a
service owns the use case → a repository owns data access.**

- **Edges:** the `SmsChannel`/`WhatsAppChannel` notification adapters and the
  `MessageController` HTTP endpoints. They translate their input into a single
  `ConversaService::send()` call and shape the response.
- **Use case:** `ConversaService` is the one send pipeline — it builds an
  `OutboundMessage`, resolves the driver via `DriverManager`, writes/updates the
  log via `MessageRepository`, invokes the driver, and records the result.
- **Data access:** `MessageRepository extends BaseRepository` owns all
  `conversa_messages` reads/writes.
- **Providers:** each `ConversaDriver` implementation performs the actual API call
  for a provider and returns a `DriverResult`. `DriverManager` selects the driver
  for a channel from config.
- **Webhooks (inbound):** `WebhookController` verifies provider authenticity and
  delegates to a per-provider status mapper that updates the log row by provider
  message id. This is *not* the framework's webhook engine — see
  [Relationship to the framework webhook engine](#relationship-to-the-framework-webhook-engine).
- **Events:** `ConversaService` and the webhook path dispatch `BaseEvent`s
  (`MessageSent`, `MessageDelivered`, `MessageFailed`) via the framework
  `EventService`, so listeners, jobs, and the framework's outbound webhook engine
  can react.

**Sync send, framework-native async.** `ConversaService::send()` is the single
synchronous pipeline (build → resolve driver → log → call provider → record +
emit event). Conversa does **not** build its own queue or job: on the notification
path the framework's `NotificationDispatcher` and `SendNotification` job already
provide queued, channel-agnostic delivery; the direct API path is synchronous and
may enqueue that same framework job when a caller wants async. See
[Alignment with the notification system](#alignment-with-the-notification-system).

### `src/` layout

```
src/
├── ConversaServiceProvider.php
├── Services/ConversaService.php
├── Channels/
│   ├── SmsChannel.php
│   └── WhatsAppChannel.php
├── Drivers/
│   ├── ConversaDriver.php          # interface
│   ├── DriverManager.php
│   ├── TwilioDriver.php
│   ├── WhatsAppCloudDriver.php
│   └── LogDriver.php
├── Support/
│   ├── OutboundMessage.php         # value object
│   └── DriverResult.php            # value object
├── Repositories/MessageRepository.php
├── Events/
│   ├── MessageSent.php              # extends Glueful\Events\Contracts\BaseEvent
│   ├── MessageDelivered.php
│   └── MessageFailed.php
├── Controllers/
│   ├── MessageController.php
│   └── WebhookController.php
└── Webhooks/
    ├── TwilioStatusMapper.php
    └── WhatsAppCloudStatusMapper.php
```

Plus `config/conversa.php`, `routes.php`,
`migrations/001_CreateConversaMessagesTable.php`, `composer.json`.

## Alignment with the notification system

Conversa is a peer to `email-notification` (`email`) and `notiva` (`push`): it
implements the same `Glueful\Notifications\Contracts\NotificationChannel` contract
and registers its channels with `ChannelManager::registerChannel()` in `boot()`.
By staying on that contract it **inherits framework behavior rather than
reimplementing it** — and it leans on the core where the siblings don't:

- **Queued/async delivery is the framework's, not Conversa's.** The framework
  already ships `NotificationDispatcher` and a channel-agnostic
  `Glueful\Queue\Jobs\SendNotification` job. On the notification path, sending the
  `sms`/`whatsapp` channel async is therefore free. Conversa builds **no** queue or
  job of its own; for async direct sends it enqueues that same framework job.
  **Framework-side change required:** `SendNotification::SUPPORTED_TYPES` currently
  lists `email, sms, push, webhook, slack, discord` — it accepts `sms` but rejects
  `whatsapp` (`validateNotificationData` throws). The implementation plan adds
  `whatsapp` to that constant so the job can carry WhatsApp; until then async is
  SMS-only. (Confirmed in `framework/src/Queue/Jobs/SendNotification.php`.)
- **Opt-out, idempotency, and pre-send hooks come from the dispatcher.** When a
  message goes through `NotificationDispatcher::send()`, the channel automatically
  gets availability checks, recipient opt-out (`shouldReceiveNotification`), a
  per-channel `delivery_idempotency_key`, and `processBeforeSend` extension hooks.
  Conversa's channels rely on these instead of duplicating them. The **direct API**
  (`POST /conversa/messages`, `ConversaService::send()`) intentionally bypasses
  **opt-out only** — it is for transactional/OTP sends where the caller owns the
  decision — while still honoring a **caller-supplied idempotency key** (see the
  controller and Error handling).
- **Events ride the framework bus.** The dispatcher already emits
  `NotificationSent` / `NotificationFailed` / `NotificationRetry` on the
  notification path; Conversa additionally emits its own message-lifecycle events
  (below) so delivery outcomes are observable on both paths.

**Where Conversa improves on its siblings:** first-class **delivery tracking**
(`conversa_messages`) plus **asynchronous status reconciliation** from provider
webhooks. `email-notification` persists nothing; `notiva` has only an optional
`track_delivery` flag and no status callback. Phone messaging needs this because
providers confirm delivery *after* the API call returns — so the message log and
webhook reconciliation are the deliberate value-add, built on `BaseRepository`,
`BaseEvent`, and the framework HTTP client rather than anything bespoke.

## Relationship to the framework webhook engine

The framework's `src/Api/Webhooks/*` engine is **outbound**: `WebhookDispatcher`
matches subscriptions, records deliveries, and queues `DeliverWebhookJob` to send
webhooks *out* to subscribers, signing them with `WebhookSignature` in Glueful's
own `t=…,v1=…` scheme. It is a publisher, not a receiver.

- **Receiving provider callbacks** (Twilio/Meta → Conversa) therefore does **not**
  use that engine. They are ordinary Glueful routes handled by `WebhookController`,
  and signature verification is **provider-specific** (Twilio `X-Twilio-Signature`,
  Meta `X-Hub-Signature-256`) — Glueful's `WebhookSignature` cannot verify those, so
  that verification lives in Conversa's per-provider mappers. This is expected, not
  a gap.
- **Emitting delivery outcomes** (Conversa → the app's subscribers) *does* compose
  with the framework engine: Conversa's `MessageDelivered` / `MessageFailed` events
  let an application surface `conversa.message.delivered` / `…failed` through the
  existing outbound webhook engine without Conversa coupling to it directly.

## Events

Conversa dispatches these `Glueful\Events\Contracts\BaseEvent` subclasses via the
framework `EventService`:

- `MessageSent` — after the provider accepts the send (`status = sent`), carrying
  the message uuid, channel, driver, recipient, and provider message id.
- `MessageDelivered` — from the webhook path when the provider confirms final
  delivery (`status = delivered`).
- `MessageFailed` — on send failure or a failed/undelivered webhook status,
  carrying the reason.

These are the framework-idiomatic integration point: app listeners/jobs subscribe
to them, and they are what feeds the outbound webhook engine described above.

## Components

### `ConversaServiceProvider` (extends `Glueful\Extensions\ServiceProvider`)
- `static services(): array` — registers `ConversaService`, `DriverManager`, the
  three drivers, both channels, `MessageRepository`, the two status mappers, and
  the controllers as shared, autowired services.
- `register(ApplicationContext $context): void` — `mergeConfig('conversa', require __DIR__.'/../config/conversa.php')`.
- `boot(ApplicationContext $context): void` — if `ChannelManager` is bound,
  `registerChannel(SmsChannel)` and `registerChannel(WhatsAppChannel)`; then
  `loadMigrationsFrom(__DIR__.'/../migrations')` and
  `loadRoutesFrom(__DIR__.'/../routes.php')`. Each step guarded (fail-fast in
  non-production, logged otherwise) so one failure never aborts framework boot.

### `ConversaService` (the send pipeline)
Responsibilities: validate input; **if an idempotency key is supplied and a row
already exists for it, return that row's result without re-sending**; build
`OutboundMessage`; create the log row (`queued`); resolve the driver
(`DriverManager::driverFor($channel)`); if the driver is unavailable, record
`failed` with reason, dispatch `MessageFailed`, and return; otherwise call
`driver->send()`, update the row from the `DriverResult` (`sent` + provider id, or
`failed` + error + raw response), dispatch `MessageSent` or `MessageFailed`, and
return the `DriverResult`. `MessageDelivered`/`MessageFailed` are also dispatched
later from the webhook path when the provider confirms final state. Events are
dispatched via the injected framework `EventService`.

Public API (signatures finalized in implementation; intended shape):
```php
// $payload is one of: ['body' => '...'] (SMS / in-session WhatsApp)
//                     ['template' => ['name' => '...', 'language' => 'en_US', 'variables' => [...]]] (business-initiated WhatsApp)
// $opts may carry ['idempotency_key' => '...', 'meta' => [...]]
public function send(string $channel, string $to, array $payload, array $opts = []): DriverResult;

// Re-sends a failed row and increments retry_count. Reuses the stored payload when
// it is available; when payload storage is off the caller MUST pass a fresh payload.
public function retry(string $messageUuid, ?array $payload = null): DriverResult;
```

`send()` validates that a `template` payload is only used on the `whatsapp`
channel and a free-text `body` is present for `sms`; an empty/oversized body is
rejected before the driver is touched.

**`retry()` and payload storage.** `retry()` reconstructs the send from the stored
`body`/`template_name`+`template_vars`. When `features.store_body=false` those
columns are omitted, so the row alone cannot be replayed: in that mode `retry()`
**requires** a `$payload` argument and throws a clear error if none is given (it
never silently sends an empty message). With storage on, `retry($uuid)` works from
the row. (A future option — an encrypted, separately-retained retry payload —
is roadmap, not v1.)

**`retry()` and template identity.** Only the logical `template_name` (and
variables) is persisted, not the resolved driver identity. So a `retry()` of a
WhatsApp template **re-resolves language/`ContentSid` from the *current*
`templates` config** — by design it uses the latest mapping, which means a config
change between the original send and the retry can shift the resolved language or
`ContentSid`. A caller that needs the exact original identity passes an explicit
`$payload` (with `template.provider_ref`) to `retry()`. (`template_language` is not
stored separately in v1; it lives in the config mapping.)

### `SmsChannel` / `WhatsAppChannel` (implement `NotificationChannel`)
Thin adapters. For each:
- `getChannelName()` → `'sms'` / `'whatsapp'`.
- `send(Notifiable $n, array $data)` → resolve recipient via
  `$n->routeNotificationFor($channel)` (expects an E.164 string), build the payload
  from `$data` (`['body' => …]`, or for WhatsApp `['template' => …]` when present),
  call `ConversaService::send($channel, $to, $payload, $opts)`, return its `ok`.
- `format(array $data, Notifiable $n)` → SMS: pass-through. WhatsApp: pass a
  `template` block through unchanged when present; otherwise treat as free-text
  body (valid only in-session). No free-text variable interpolation in v1.
- `isAvailable()` → delegate to whether a usable driver is configured for the
  channel.
- `getConfig()` → the channel's resolved config slice.

### `ConversaDriver` (interface) + implementations
```php
interface ConversaDriver
{
    public function getName(): string;                 // 'twilio' | 'whatsapp_cloud' | 'log'
    public function supports(string $channel): bool;    // which of sms/whatsapp it can serve
    public function isAvailable(): bool;                // config/credentials present
    public function send(OutboundMessage $message): DriverResult;
}
```
- `TwilioDriver` — `supports('sms')` and `supports('whatsapp')`; posts to the
  Twilio Messages API via the framework HTTP client; `from` resolves to
  `sms_from` or `whatsapp_from` by channel. A WhatsApp **template** resolves to a
  Twilio **`ContentSid`** (+ `ContentVariables` from the template variables) via the
  `templates.{name}.twilio.content_sid` mapping; a free-text body maps to `Body`.
  Returns the Twilio message SID as `providerMessageId`.
- `WhatsAppCloudDriver` — `supports('whatsapp')` only; posts to
  `graph.facebook.com/.../{phone_id}/messages`. A **template** maps to a
  `type: template` payload (`template.name`, `template.language.code`,
  `template.components` from the variables), resolving name/language via
  `templates.{name}.whatsapp_cloud` (defaulting to the logical name); a free-text
  body maps to `type: text` (in-session only). Returns the message id from the
  response.

> **Template identity resolution.** Callers reference a **logical** template name;
> each driver resolves its own identifier from the `templates` config map (Meta:
> name + language; Twilio: `ContentSid`). A payload may also carry a driver-specific
> override (`template.provider_ref`) as an escape hatch. If a template is sent on a
> driver with no mapping and no override, the send is rejected with a clear
> configuration error (never sent half-formed).
- `LogDriver` — `supports` both; always available; writes the `OutboundMessage`
  (body or template) to the `conversa` log channel and returns a synthetic
  `providerMessageId`. For credential-free local/test runs.

### `DriverManager`
`driverFor(string $channel): ConversaDriver` — reads `conversa.default[$channel]`
to pick the driver key, returns the registered driver instance, and asserts
`supports($channel)`. Exposes `available(string $channel): bool` for channel
`isAvailable()`.

### `MessageRepository` (extends `BaseRepository`, `protected string $table = 'conversa_messages'`)
- `create(array $row): string` (returns uuid) — initial `queued` row.
- `markSent(string $uuid, DriverResult $r)`, `markFailed(string $uuid, DriverResult $r)`.
- `updateStatusByProviderId(string $driver, string $providerMessageId, string $status, array $raw = [])` — used by webhooks; matches on the `(driver, provider_message_id)` index.
- `findByIdempotencyKey(string $channel, string $key)` — returns an existing row for a caller-supplied idempotency key (per channel), or null.
- `query(array $filters)` — for `GET /conversa/messages` (status, recipient, channel, date range, pagination).
- `findByUuid(string $uuid)`.

### `OutboundMessage` / `DriverResult` (value objects)
- `OutboundMessage`: `channel`, `to`, `from`, `body` (?string), `template`
  (?array: `name`, `language`, `variables`, optional `provider_ref` driver-specific
  id override), `idempotencyKey` (?string), `meta`, optional
  `notifiableType`/`notifiableId`. Exactly one of `body` / `template` is set.
- `DriverResult`: `ok` (bool), `providerMessageId` (?string), `rawResponse`
  (array), `error` (?string).

### Controllers
- `MessageController::store` → `POST /conversa/messages` — validates
  `{channel, to}` plus exactly one of `body` / `template` (template only for
  `whatsapp`); reads an optional `Idempotency-Key` header (or `idempotency_key`
  field); calls `ConversaService::send`; returns the persisted message
  (uuid + status) or a structured error. A repeat idempotency key returns the
  prior message without re-sending.
- `MessageController::index` → `GET /conversa/messages` — `MessageRepository::query`.
- `WebhookController::verify` → `GET /conversa/webhooks/{provider}` — provider
  handshake (e.g. Meta verify-token echo); fails closed if the token doesn't match.
- `WebhookController::handle` → `POST /conversa/webhooks/{provider}` — **verify the
  provider signature first and fail closed** (see Error handling), then run the
  provider's status mapper and update the log row.

## Data flow

1. **Direct send:** `MessageController` → `ConversaService::send` → `DriverManager` → `driver->send` → `DriverResult` → `MessageRepository` records `sent`/`failed` → dispatch `MessageSent`/`MessageFailed`.
2. **Notification (sync):** `NotificationDispatcher::send` (applies opt-out + idempotency + `processBeforeSend`) → `SmsChannel|WhatsAppChannel::send` → `routeNotificationFor` → `ConversaService::send` (same pipeline as #1).
3. **Notification (async):** `Glueful\Queue\Jobs\SendNotification` (framework job) → `NotificationService` → same channel path as #2. Conversa adds no job of its own.
4. **Delivery webhook:** provider → `WebhookController::handle` → verify (provider-specific) → `*StatusMapper` → `MessageRepository::updateStatusByProviderId` → dispatch `MessageDelivered`/`MessageFailed`.

Log-row lifecycle: `queued` (before driver call) → `sent` | `failed` (from
`DriverResult`, emits `MessageSent`/`MessageFailed`) → `delivered` | `undelivered`
(from webhook, emits `MessageDelivered`/`MessageFailed`).

## Data model — `conversa_messages`

| Column | Type | Notes |
| ------ | ---- | ----- |
| `id` | bigint, PK, auto-increment | |
| `uuid` | string(12) | unique |
| `channel` | enum `sms`,`whatsapp` | |
| `driver` | string(32) | resolved driver key |
| `to` | string(32) | recipient, E.164 |
| `from` | string(64), nullable | resolved sender |
| `body` | text, nullable | free-text body (SMS / in-session WhatsApp); omitted when `features.store_body=false` |
| `template_name` | string(128), nullable | WhatsApp template name when sent as a template |
| `template_vars` | json, nullable | template variables; redacted/omitted under the body privacy rules (often contain codes/PII) |
| `idempotency_key` | string(128), nullable | caller-supplied key for direct sends |
| `status` | enum `queued`,`sent`,`delivered`,`failed`,`undelivered` | default `queued` |
| `provider_message_id` | string(255), nullable | |
| `provider_response` | json, nullable | last raw provider payload, redacted per `redact_provider_response` |
| `error` | string(500), nullable | last failure reason |
| `retry_count` | int | default 0 |
| `notifiable_type` | string(100), nullable | when sent via a Notifiable |
| `notifiable_id` | string(255), nullable | |
| `created_at` | timestamp | default CURRENT_TIMESTAMP |
| `sent_at` | timestamp, nullable | |
| `delivered_at` | timestamp, nullable | |
| `updated_at` | timestamp, nullable | |

Indexes:
- unique `uuid`
- **composite `(driver, provider_message_id)`** — serves `updateStatusByProviderId`; **unique where `provider_message_id IS NOT NULL`** (a row is `queued` with a null id before the provider responds, so nulls must be allowed; the partial-unique form is applied where the DB supports it, otherwise enforced in the repository write).
- **unique `(channel, idempotency_key)` where `idempotency_key IS NOT NULL`** — enforces direct-send dedupe.
- index `status`, `to`, `created_at`.

## Configuration — `config/conversa.php`

```php
return [
    'default' => [
        'sms'      => env('CONVERSA_SMS_DRIVER', 'twilio'),       // twilio | log
        'whatsapp' => env('CONVERSA_WHATSAPP_DRIVER', 'whatsapp_cloud'), // whatsapp_cloud | twilio | log
    ],
    'drivers' => [
        'twilio' => [
            'enabled'       => (bool) env('CONVERSA_TWILIO_ENABLED', true),
            'sid'           => env('CONVERSA_TWILIO_SID'),
            'token'         => env('CONVERSA_TWILIO_TOKEN'),
            'sms_from'      => env('CONVERSA_TWILIO_SMS_FROM'),
            'whatsapp_from' => env('CONVERSA_TWILIO_WHATSAPP_FROM'),
        ],
        'whatsapp_cloud' => [
            'enabled'      => (bool) env('CONVERSA_WHATSAPP_ENABLED', true),
            'phone_id'     => env('CONVERSA_WHATSAPP_PHONE_ID'),
            'token'        => env('CONVERSA_WHATSAPP_TOKEN'),
            'verify_token' => env('CONVERSA_WHATSAPP_VERIFY_TOKEN'),
            'app_secret'   => env('CONVERSA_WHATSAPP_APP_SECRET'),
        ],
        'log' => ['enabled' => true],
    ],
    // Logical WhatsApp template name → per-driver identity. Callers reference the
    // logical name; each driver resolves its own identifier. Meta uses name +
    // language; Twilio's Content API needs a ContentSid.
    'templates' => [
        // 'order_shipped' => [
        //     'whatsapp_cloud' => ['name' => 'order_shipped', 'language' => 'en_US'],
        //     'twilio'         => ['content_sid' => 'HXxxxxxxxxxxxxxxxxxxxxxxxxx'],
        // ],
    ],
    'features' => [
        'store_body'               => (bool) env('CONVERSA_STORE_BODY', true),               // also gates template_vars
        'redact_provider_response' => (bool) env('CONVERSA_REDACT_PROVIDER_RESPONSE', true),  // strip PII before persisting provider_response
        'max_retries'              => (int) env('CONVERSA_MAX_RETRIES', 3),
        'log_messages'             => (bool) env('CONVERSA_LOG_MESSAGES', true),
    ],
];
```

A driver whose `enabled` is false or whose required credentials are missing
reports `isAvailable() === false`.

## Routes — `routes.php`

```
POST   /conversa/messages            auth + rate_limit   send SMS/WhatsApp
GET    /conversa/messages            auth + rate_limit   query the message log
GET    /conversa/webhooks/{provider} public              provider handshake
POST   /conversa/webhooks/{provider} public (verified)   delivery-status callback
```

`{provider}` is the driver key (`twilio`, `whatsapp_cloud`).

## Error handling

- **Driver unavailable** (disabled or missing credentials): `ConversaService`
  records the row as `failed` with a clear reason and returns a failed
  `DriverResult`. The notification channel logs and skips (mirrors `EmailChannel`'s
  graceful behavior). No exception escapes into framework boot or the request
  pipeline.
- **Provider API/HTTP error:** record `failed` + `error` + raw response; the direct
  API returns a structured error envelope; the channel returns `false`.
- **Webhooks fail closed.** If the configured provider requires a signature/verify
  token and it is missing or invalid, respond `403` and make **no** state change —
  never fail open. Verification inputs are pinned per provider:
  - *Meta WhatsApp Cloud:* HMAC-SHA256 of the **raw, unparsed request body** keyed
    by `app_secret`, compared (timing-safe) to `X-Hub-Signature-256`; the `GET`
    handshake must echo `hub.challenge` only when `hub.verify_token` matches.
  - *Twilio:* HMAC-SHA1 over the **full external request URL** + sorted POST params,
    compared to `X-Twilio-Signature`. The "full external URL" must be reconstructed
    from the proxy-forwarded scheme/host/port (`X-Forwarded-Proto`/`-Host`), since
    Conversa typically runs behind a load balancer and a mismatch here silently
    breaks verification. The expected external base URL is configurable to avoid
    trusting spoofable headers blindly.
- **Idempotent direct sends:** when an `Idempotency-Key` is supplied, a repeat key
  for the same channel returns the existing message (no second provider call /
  charge). The `(channel, idempotency_key)` partial-unique index is the backstop
  against a race creating duplicates.
- **`log` driver** is selectable via `CONVERSA_*_DRIVER=log` for credential-free
  dev/test; it is **not** a silent fallback for a misconfigured real driver — that
  path records a failure with the reason so misconfiguration is visible.

## Security

- Treat all provider credentials as secrets; never commit them.
- Verify webhook authenticity before acting and **fail closed** (see Error
  handling) — never process an unsigned/invalid callback.
- The DB log may store the body and template variables for audit (`store_body`,
  default true), but **application logs never contain the message body, template
  variables, or any verification code**; set `store_body=false` for
  compliance-sensitive deployments (it also suppresses `template_vars`).
- **`provider_response` is redacted before persistence** when
  `redact_provider_response=true` (default): provider payloads can carry phone
  numbers, message text, pricing, error detail, and user identifiers, so recipient
  numbers and message/template content are masked and only delivery-relevant
  fields (status, provider id, error code) are kept. Set it false only in trusted,
  short-retention debugging environments.
- Rate-limit the send endpoint (applied via route middleware) to prevent abuse and
  runaway provider spend.
- Store recipient numbers in E.164 and treat them as personal data.

## Testing strategy

- **Unit:**
  - `DriverManager` resolves the correct driver per channel/config and rejects an
    unsupported channel.
  - Each driver builds the correct provider request (mocked HTTP client) and maps
    success/error responses to `DriverResult` — including the **WhatsApp template
    payload** per driver: Meta resolves to `type: template` (name+language), Twilio
    resolves to a `ContentSid` via the `templates` map; a `provider_ref` override is
    honored; a template with no mapping/override is **rejected** with a config error.
  - `ConversaService` happy path and failure path, including the
    `queued → sent`/`queued → failed` log-row lifecycle, `retry()`, that
    `MessageSent`/`MessageFailed` are dispatched (assert via a spy `EventService`),
    and **idempotency**: a repeat `idempotency_key` returns the existing row and
    does not call the driver a second time.
  - `retry()` with `store_body=false`: calling `retry($uuid)` with **no** payload
    errors clearly (no empty send); calling `retry($uuid, $payload)` with a fresh
    payload resends and increments `retry_count`. With `store_body=true`,
    `retry($uuid)` resends from the stored row.
  - Channel validation: `template` payload rejected on `sms`; missing `body`
    rejected on `sms`.
  - `SmsChannel`/`WhatsAppChannel` correctly translate a `Notifiable` (free-text or
    template) into a `ConversaService::send()` call and honor `isAvailable()`.
  - Webhook **fail-closed**: valid signature accepted; missing/invalid →
    `403` with no state change; provider status mapping correct; a confirmed
    delivery dispatches `MessageDelivered`. Include a Twilio case asserting the
    full external URL (proxy-forwarded scheme/host) is used in the signature base.
  - `MessageRepository` create/update/query, the `(driver, provider_message_id)`
    lookup, and `findByIdempotencyKey`.
- **Offline end-to-end:** channel → service → `LogDriver` → repository, asserting
  the persisted row — no network access required.

## `composer.json` essentials

- `name`: `glueful/conversa`, `type`: `glueful-extension`.
- PSR-4: `Glueful\\Extensions\\Conversa\\` → `src/`.
- `extra.glueful`: `provider` = `Glueful\\Extensions\\Conversa\\ConversaServiceProvider`,
  `requires.glueful` = `>=1.49.0`.
- No mandatory third-party SDK: provider calls go through the framework HTTP
  client. (If a vendor SDK is later preferred for a driver, add it under
  `suggest`.)

## Dependencies

- Glueful Framework 1.49.0+ (`ChannelManager`, `NotificationChannel`, `Notifiable`,
  `BaseRepository`, `EventService`, HTTP client, migrations, routing, rate-limit
  middleware).
- A configured Twilio or Meta WhatsApp Cloud account for live sending (the `log`
  driver needs none).

### Framework-side changes (tracked in the implementation plan)

- **`auth_basic` passthrough** in `framework/src/Http/Client.php::transformOptions()`
  so per-request HTTP Basic auth reaches Symfony HttpClient (the Twilio driver relies
  on it). Previously `auth_basic` was silently dropped.
- Add `'whatsapp'` to `SUPPORTED_TYPES` in
  `framework/src/Queue/Jobs/SendNotification.php` (and a `whatsapp` timeout arm in
  its `match`) so the framework notification job can carry WhatsApp for async
  delivery. Both small, additive, backward-compatible.

## Future (roadmap, not this spec)

Inbound messages + conversation threads; a template **registry/management** layer
and free-text variable interpolation (v1 only *sends* an already-approved WhatsApp
template by name); campaigns/broadcasts and scheduled/batch sends; contact lists
and opt-in/preference management; additional drivers
(Africa's Talking, Vonage, and other regional gateways).

(Async per-message delivery is **not** a future item — it's available in v1 via the
framework's `SendNotification` job; only Conversa-specific batch/scheduled sending
is future work.)
