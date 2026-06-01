<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Glueful\Http\Client;

final class WhatsAppCloudDriver implements ConversaDriver
{
    /**
     * @param array<string,mixed> $config Driver config (phone_id, token, ...)
     * @param array<string,array<string,mixed>> $templates Logical name => per-driver identity
     */
    public function __construct(
        private readonly Client $http,
        private readonly array $config,
        private readonly array $templates,
    ) {
    }

    public function getName(): string
    {
        return 'whatsapp_cloud';
    }

    public function supports(string $channel): bool
    {
        return $channel === 'whatsapp';
    }

    public function isAvailable(string $channel): bool
    {
        return $channel === 'whatsapp'
            && (bool) ($this->config['enabled'] ?? false)
            && ($this->config['phone_id'] ?? null) !== null
            && ($this->config['token'] ?? null) !== null;
    }

    public function send(OutboundMessage $message): DriverResult
    {
        $to = ltrim($message->to, '+');
        $body = ['messaging_product' => 'whatsapp', 'to' => $to];

        if ($message->isTemplate()) {
            $tpl = $message->template;
            $identity = ($tpl['provider_ref'] ?? null) !== null
                ? ['name' => $tpl['provider_ref']]
                : ($this->templates[$tpl['name']]['whatsapp_cloud'] ?? null);

            if ($identity === null) {
                return DriverResult::failed("No whatsapp_cloud mapping for template '{$tpl['name']}'.");
            }

            $body['type'] = 'template';
            $body['template'] = [
                'name' => $identity['name'] ?? $tpl['name'],
                'language' => ['code' => $identity['language'] ?? ($tpl['language'] ?? 'en_US')],
                'components' => $this->components($tpl['variables'] ?? []),
            ];
        } else {
            $body['type'] = 'text';
            $body['text'] = ['body' => (string) $message->body];
        }

        try {
            $resp = $this->http->post(
                sprintf('https://graph.facebook.com/v19.0/%s/messages', $this->config['phone_id']),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $this->config['token']],
                    'json' => $body,
                ]
            );
            $data = $resp->json();
            if (!$resp->isSuccessful()) {
                return DriverResult::failed(
                    'whatsapp_cloud_http_' . $resp->getStatusCode(),
                    is_array($data) ? $data : [],
                );
            }
            $id = $data['messages'][0]['id'] ?? null;

            return DriverResult::ok($id, is_array($data) ? $data : []);
        } catch (\Throwable $e) {
            return DriverResult::failed('whatsapp_cloud_exception: ' . $e->getMessage());
        }
    }

    /**
     * @param array<int|string,mixed> $variables
     * @return array<int,array<string,mixed>>
     */
    private function components(array $variables): array
    {
        if ($variables === []) {
            return [];
        }
        $params = array_map(static fn($v) => ['type' => 'text', 'text' => (string) $v], array_values($variables));

        return [['type' => 'body', 'parameters' => $params]];
    }
}
