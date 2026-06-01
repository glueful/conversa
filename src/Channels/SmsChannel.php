<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Channels;

final class SmsChannel extends AbstractConversaChannel
{
    public function getChannelName(): string
    {
        return 'sms';
    }
}
