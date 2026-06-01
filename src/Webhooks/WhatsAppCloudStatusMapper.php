<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Webhooks;

final class WhatsAppCloudStatusMapper implements StatusMapper
{
    private const STATUS_MAP = [
        'sent' => 'sent',
        'delivered' => 'delivered',
        'read' => 'delivered',
        'failed' => 'failed',
    ];

    public function driverName(): string
    {
        return 'whatsapp_cloud';
    }

    public function verify(string $rawBody, array $headers, string $fullUrl, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false; // configured to require a secret but none set => fail closed
        }
        $header = $headers['x-hub-signature-256'] ?? '';
        if ($header === '' || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }

    public function mapAll(array $payload): array
    {
        $out = [];
        $entries = $payload['entry'] ?? [];
        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $id = $status['id'] ?? null;
                    $raw = $status['status'] ?? null;
                    if ($id === null || $raw === null) {
                        continue;
                    }
                    $out[] = [
                        'provider_message_id' => (string) $id,
                        'status' => self::STATUS_MAP[$raw] ?? 'undelivered',
                    ];
                }
            }
        }

        return $out;
    }

    public function map(array $payload): ?array
    {
        return $this->mapAll($payload)[0] ?? null;
    }
}
