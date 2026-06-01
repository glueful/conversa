<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Repositories;

use Glueful\Repository\BaseRepository;

final class MessageRepository extends BaseRepository
{
    public function getTableName(): string
    {
        return 'conversa_messages';
    }

    /**
     * Update the row matching (driver, provider_message_id) — used by webhooks.
     *
     * @param array<string,mixed> $raw Raw (already-redacted) provider payload
     */
    public function updateStatusByProviderId(
        string $driver,
        string $providerMessageId,
        string $status,
        array $raw = [],
    ): bool {
        $data = ['status' => $status];
        if ($status === 'delivered') {
            $data['delivered_at'] = date('Y-m-d H:i:s');
        }
        if ($raw !== []) {
            $data['provider_response'] = json_encode($raw, JSON_THROW_ON_ERROR);
        }

        $affected = $this->getConnection()->table($this->getTableName())
            ->where('driver', '=', $driver)
            ->where('provider_message_id', '=', $providerMessageId)
            ->update($data);

        return $affected > 0;
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(string $channel, string $key): ?array
    {
        $row = $this->getConnection()->table($this->getTableName())
            ->where('channel', '=', $channel)
            ->where('idempotency_key', '=', $key)
            ->first();

        return $row ?: null;
    }

    /**
     * Fetch the row a webhook refers to. Scoped by (driver, provider_message_id)
     * so two drivers cannot collide on the same provider id.
     *
     * @return array<string,mixed>|null
     */
    public function findByProviderMessageId(string $driver, string $providerMessageId): ?array
    {
        $row = $this->getConnection()->table($this->getTableName())
            ->where('driver', '=', $driver)
            ->where('provider_message_id', '=', $providerMessageId)
            ->first();

        return $row ?: null;
    }
}
