<?php

declare(strict_types=1);

use Glueful\Extensions\Conversa\Controllers\MessageController;
use Glueful\Extensions\Conversa\Controllers\WebhookController;
use Glueful\Routing\Router;

/** @var Router $router Router instance injected by ServiceProvider::loadRoutesFrom() */

$router->group(['prefix' => '/conversa'], function (Router $router) {
    /**
     * @route POST /conversa/messages
     * @summary Send Message
     * @description Sends an SMS or WhatsApp message through the configured provider driver.
     *   Provide exactly one of `body` (free text) or `template` (WhatsApp only). Supply an
     *   `Idempotency-Key` header (or `idempotency_key` field) to make repeat sends safe;
     *   HTTP idempotency keys are scoped to the authenticated user.
     *   Requires the authenticated user to hold `conversa.messages.send`.
     * @tag Conversa
     * @requestBody
     *   channel:string="Channel: sms|whatsapp"
     *   to:string="Recipient phone number in E.164 format (e.g., +15551234567)"
     *   body:string="Message text (use this OR template)"
     *   template:object="WhatsApp template: {name, language, variables} (use this OR body)"
     *   idempotency_key:string="Optional idempotency key (alternative to the Idempotency-Key header)"
     * {required=channel,to}
     * @response 200 application/json "Message accepted (or send failed; see `ok`/`error` in data)"
     * @response 422 "Validation failed (missing channel/to, invalid E.164 recipient, or invalid body/template combination)"
     * @response 403 "Missing conversa.messages.send permission"
     */
    $router->post('/messages', [MessageController::class, 'store'])
        ->middleware(['auth', 'conversa_permission:conversa.messages.send', 'rate_limit:60,1']);

    /**
     * @route GET /conversa/messages
     * @summary List Messages
     * @description Lists logged messages (most recent first), optionally filtered by
     *   status, channel, or recipient. Requires `conversa.messages.read` because the log
     *   can contain recipients and message bodies when body storage is enabled.
     * @tag Conversa
     * @response 200 application/json "Messages retrieved"
     * @response 403 "Missing conversa.messages.read permission"
     */
    $router->get('/messages', [MessageController::class, 'index'])
        ->middleware(['auth', 'conversa_permission:conversa.messages.read', 'rate_limit:100,1']);

    /**
     * @route GET /conversa/webhooks/{provider}
     * @summary Verify Webhook (Meta handshake)
     * @description Handles the Meta WhatsApp Cloud subscription handshake. Echoes the
     *   `hub.challenge` as text/plain when `hub.verify_token` matches the configured token.
     *   `{provider}` is the driver key (e.g., `whatsapp_cloud`). Public, no auth.
     * @tag Conversa
     * @response 200 text/plain "Challenge echoed (subscription verified)"
     * @response 403 "Invalid verify token"
     */
    $router->get('/webhooks/{provider}', [WebhookController::class, 'verify']);

    /**
     * @route POST /conversa/webhooks/{provider}
     * @summary Receive Delivery Status
     * @description Receives provider delivery-status callbacks and updates the matching
     *   message log. `{provider}` is the driver key (e.g., `twilio`, `whatsapp_cloud`).
     *   Public; the request signature is verified inside the controller (fail-closed).
     * @tag Conversa
     * @response 200 application/json "Status processed"
     * @response 403 "Invalid signature"
     * @response 404 "Unknown provider"
     */
    $router->post('/webhooks/{provider}', [WebhookController::class, 'handle']);
});
