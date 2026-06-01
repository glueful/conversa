<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Support;

/**
 * Outcome of a single driver send attempt.
 */
final class DriverResult
{
    /** @param array<string,mixed> $rawResponse */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $providerMessageId = null,
        public readonly array $rawResponse = [],
        public readonly ?string $error = null,
    ) {
    }

    /** @param array<string,mixed> $rawResponse */
    public static function ok(?string $providerMessageId, array $rawResponse = []): self
    {
        return new self(true, $providerMessageId, $rawResponse, null);
    }

    /** @param array<string,mixed> $rawResponse */
    public static function failed(string $error, array $rawResponse = []): self
    {
        return new self(false, null, $rawResponse, $error);
    }
}
