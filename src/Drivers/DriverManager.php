<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

/**
 * Resolves the configured ConversaDriver for a channel ('sms' | 'whatsapp').
 */
final class DriverManager
{
    /**
     * @param array<string,string> $default Channel => driver-key map
     * @param array<string,ConversaDriver> $drivers Driver-key => instance
     */
    public function __construct(
        private readonly array $default,
        private readonly array $drivers,
    ) {
    }

    public function driverFor(string $channel): ConversaDriver
    {
        $key = $this->default[$channel] ?? null;
        if ($key === null) {
            throw new \RuntimeException("Conversa: no driver configured for channel '{$channel}'.");
        }

        $driver = $this->drivers[$key] ?? null;
        if ($driver === null) {
            throw new \RuntimeException("Conversa: driver '{$key}' is not registered.");
        }

        if (!$driver->supports($channel)) {
            throw new \RuntimeException("Conversa: driver '{$key}' does not support channel '{$channel}'.");
        }

        return $driver;
    }

    public function available(string $channel): bool
    {
        $key = $this->default[$channel] ?? null;
        if ($key === null || !isset($this->drivers[$key])) {
            return false;
        }
        $driver = $this->drivers[$key];

        return $driver->supports($channel) && $driver->isAvailable($channel);
    }

    /** The configured driver key for a channel, without instantiating/validating it. */
    public function driverKeyFor(string $channel): string
    {
        return $this->default[$channel] ?? 'unknown';
    }
}
