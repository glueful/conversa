<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Channels;

use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;

abstract class AbstractConversaChannel implements NotificationChannel
{
    public function __construct(
        protected readonly ConversaService $conversa,
        protected readonly bool $available,
    ) {
    }

    abstract public function getChannelName(): string;

    public function send(Notifiable $notifiable, array $data): bool
    {
        $to = $notifiable->routeNotificationFor($this->getChannelName());
        if (!is_string($to) || $to === '') {
            return false;
        }

        $payload = isset($data['template'])
            ? ['template' => $data['template']]
            : ['body' => (string) ($data['body'] ?? '')];

        $opts = [];
        if (isset($data['_meta']['delivery_idempotency_key'])) {
            $opts['idempotency_key'] = (string) $data['_meta']['delivery_idempotency_key'];
        }

        return $this->conversa->send($this->getChannelName(), $to, $payload, $opts)->ok;
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
