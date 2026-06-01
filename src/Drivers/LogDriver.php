<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Psr\Log\LoggerInterface;

/**
 * Writes the message to the log instead of calling a provider. Safe default for
 * credential-free local/test runs. Never logs raw bodies/template variables.
 */
final class LogDriver implements ConversaDriver
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getName(): string
    {
        return 'log';
    }

    public function supports(string $channel): bool
    {
        return in_array($channel, ['sms', 'whatsapp'], true);
    }

    public function isAvailable(string $channel): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): DriverResult
    {
        $id = 'log_' . bin2hex(random_bytes(8));
        $this->logger->info('conversa.log_driver.send', [
            'channel' => $message->channel,
            'to' => $message->to,
            'kind' => $message->isTemplate() ? 'template' : 'text',
            'provider_message_id' => $id,
        ]);

        return DriverResult::ok($id, ['driver' => 'log']);
    }
}
