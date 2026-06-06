<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Channels;

use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\RichNotificationChannel;
use Glueful\Notifications\Results\NotificationResult;

abstract class AbstractConversaChannel implements RichNotificationChannel
{
    public function __construct(
        protected readonly ConversaService $conversa,
        protected readonly bool $available,
    ) {
    }

    abstract public function getChannelName(): string;

    public function send(Notifiable $notifiable, array $data): bool
    {
        return $this->sendNotification($notifiable, $data)->success;
    }

    /**
     * Send via Conversa and adapt the driver outcome to a structured {@see NotificationResult}.
     *
     * The provider message id and raw provider response carry through from {@see DriverResult};
     * a missing route is a non-retryable `no_recipient`, a driver failure is a retryable
     * `send_failed` (the underlying driver error is surfaced as the message).
     *
     * @param array<string, mixed> $data
     */
    public function sendNotification(Notifiable $notifiable, array $data): NotificationResult
    {
        $to = $notifiable->routeNotificationFor($this->getChannelName());
        if (!is_string($to) || $to === '') {
            return NotificationResult::failure(
                errorCode: 'no_recipient',
                errorMessage: 'Notifiable has no ' . $this->getChannelName() . ' route address.',
                retryable: false
            );
        }

        $payload = isset($data['template'])
            ? ['template' => $data['template']]
            : ['body' => (string) ($data['body'] ?? '')];

        $opts = [];
        if (isset($data['_meta']['delivery_idempotency_key'])) {
            $opts['idempotency_key'] = (string) $data['_meta']['delivery_idempotency_key'];
        }

        $start = microtime(true);
        $result = $this->conversa->send($this->getChannelName(), $to, $payload, $opts);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($result->ok) {
            return NotificationResult::success(
                providerMessageId: $result->providerMessageId,
                latencyMs: $latencyMs,
                metadata: $result->rawResponse
            );
        }

        return NotificationResult::failure(
            errorCode: 'send_failed',
            errorMessage: $result->error,
            retryable: true,
            latencyMs: $latencyMs,
            metadata: $result->rawResponse
        );
    }

    public function format(array $data, Notifiable $notifiable): array
    {
        return $data;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getConfig(): array
    {
        return [];
    }
}
