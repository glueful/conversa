<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Webhooks;

final class TwilioStatusMapper implements StatusMapper
{
    private const STATUS_MAP = [
        'queued' => 'sent',
        'sent' => 'sent',
        'delivered' => 'delivered',
        'undelivered' => 'undelivered',
        'failed' => 'failed',
    ];

    public function driverName(): string
    {
        return 'twilio';
    }

    public function verify(string $rawBody, array $headers, string $fullUrl, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false;
        }
        $sig = $headers['x-twilio-signature'] ?? '';
        if ($sig === '') {
            return false;
        }
        // Twilio: HMAC-SHA1 over the full external URL + sorted POST params, then base64.
        parse_str($rawBody, $params);
        ksort($params);
        $data = $fullUrl;
        foreach ($params as $k => $v) {
            $data .= $k . (is_array($v) ? implode('', $v) : (string) $v);
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $secret, true));

        return hash_equals($expected, $sig);
    }

    public function mapAll(array $payload): array
    {
        $id = $payload['MessageSid'] ?? $payload['SmsSid'] ?? null;
        $raw = $payload['MessageStatus'] ?? $payload['SmsStatus'] ?? null;
        if ($id === null || $raw === null) {
            return [];
        }

        return [[
            'provider_message_id' => (string) $id,
            'status' => self::STATUS_MAP[$raw] ?? 'undelivered',
        ]];
    }

    public function map(array $payload): ?array
    {
        return $this->mapAll($payload)[0] ?? null;
    }
}
