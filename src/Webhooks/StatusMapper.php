<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Webhooks;

interface StatusMapper
{
    public function driverName(): string;

    /**
     * Verify provider authenticity. Fail closed: missing/invalid => false.
     *
     * @param array<string,string> $headers Lower-cased header name => value
     */
    public function verify(string $rawBody, array $headers, string $fullUrl, ?string $secret): bool;

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array{provider_message_id:string,status:string}>
     */
    public function mapAll(array $payload): array;

    /**
     * Convenience for a single status (first one).
     *
     * @param array<string,mixed> $payload
     * @return array{provider_message_id:string,status:string}|null
     */
    public function map(array $payload): ?array;
}
