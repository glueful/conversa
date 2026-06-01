<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Controllers;

use Glueful\Extensions\Conversa\Events\MessageDelivered;
use Glueful\Extensions\Conversa\Events\MessageFailed;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Extensions\Conversa\Webhooks\StatusMapper;
use Glueful\Http\Response as ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebhookController
{
    /**
     * @param array<string,StatusMapper> $mappers driver-key => mapper
     * @param array<string,mixed> $driverConfig driver-key => config (for secrets)
     * @param callable(object):void $dispatch
     * @param string|null $webhookBaseUrl Public base URL (e.g. https://api.example.com) used
     *        to reconstruct the external callback URL Twilio signed, when running behind a
     *        proxy/load balancer. Null => use the request URI as-is.
     */
    public function __construct(
        private readonly array $mappers,
        private readonly array $driverConfig,
        private readonly MessageRepository $repository,
        private $dispatch,
        private readonly ?string $webhookBaseUrl = null,
    ) {
    }

    public function verify(Request $request, string $provider): Response
    {
        // Meta hub challenge handshake — must echo the raw challenge as text/plain.
        // Meta sends hub.mode / hub.verify_token / hub.challenge; depending on how the
        // request is constructed, dots may be preserved or mangled to underscores by
        // PHP query parsing, so accept both forms.
        $q = $request->query;
        $mode = $q->get('hub.mode') ?? $q->get('hub_mode');
        $token = $q->get('hub.verify_token') ?? $q->get('hub_verify_token');
        $challenge = (string) ($q->get('hub.challenge') ?? $q->get('hub_challenge') ?? '');
        $expected = $this->driverConfig[$provider]['verify_token'] ?? null;

        if ($mode === 'subscribe' && $expected !== null && hash_equals((string) $expected, (string) $token)) {
            return new Response($challenge, 200, ['Content-Type' => 'text/plain']);
        }

        return ApiResponse::forbidden('Invalid verify token');
    }

    public function handle(Request $request, string $provider): Response
    {
        $mapper = $this->mappers[$provider] ?? null;
        if ($mapper === null) {
            return ApiResponse::notFound('Unknown provider');
        }

        $raw = (string) $request->getContent();
        $headers = [];
        foreach ($request->headers->keys() as $key) {
            $headers[strtolower($key)] = (string) $request->headers->get($key);
        }
        $secret = $this->driverConfig[$provider]['app_secret']
            ?? $this->driverConfig[$provider]['token']
            ?? null;
        // Twilio signs the PUBLIC callback URL. Behind a proxy/LB the internal
        // request URI differs, so prefer a configured external base + request URI.
        $fullUrl = ($this->webhookBaseUrl !== null && $this->webhookBaseUrl !== '')
            ? rtrim($this->webhookBaseUrl, '/') . $request->getRequestUri()
            : $request->getUri();

        if (!$mapper->verify($raw, $headers, $fullUrl, $secret)) {
            return ApiResponse::forbidden('Invalid signature');
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            parse_str($raw, $payload); // Twilio posts form-encoded
        }

        foreach ($mapper->mapAll($payload) as $entry) {
            $this->repository->updateStatusByProviderId($provider, $entry['provider_message_id'], $entry['status']);
            // Fetch by (driver, id) — never by id alone — so a shared provider id
            // across drivers can't resolve to the wrong row.
            $row = $this->repository->findByProviderMessageId($provider, $entry['provider_message_id']);
            if ($row === null) {
                continue;
            }
            if ($entry['status'] === 'delivered') {
                ($this->dispatch)(new MessageDelivered($row['uuid'], $row['channel'], $provider, $entry['provider_message_id']));
            } elseif (in_array($entry['status'], ['failed', 'undelivered'], true)) {
                ($this->dispatch)(new MessageFailed($row['uuid'], $row['channel'], $provider, $row['to'], $entry['status']));
            }
        }

        return ApiResponse::success(['received' => true]);
    }
}
