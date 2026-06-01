<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;

interface ConversaDriver
{
    /** Driver key, e.g. 'twilio' | 'whatsapp_cloud' | 'log'. */
    public function getName(): string;

    /** Whether this driver can serve the given channel ('sms' | 'whatsapp'). */
    public function supports(string $channel): bool;

    /**
     * Whether the required configuration/credentials are present FOR THIS CHANNEL.
     * Channel-aware because a multi-channel driver (Twilio) may be configured for
     * SMS but not WhatsApp (missing whatsapp_from), or vice-versa.
     */
    public function isAvailable(string $channel): bool;

    public function send(OutboundMessage $message): DriverResult;
}
