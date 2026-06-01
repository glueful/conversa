<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Channels;

final class WhatsAppChannel extends AbstractConversaChannel
{
    public function getChannelName(): string
    {
        return 'whatsapp';
    }
}
