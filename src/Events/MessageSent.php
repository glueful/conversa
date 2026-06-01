<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Events;

use Glueful\Events\Contracts\BaseEvent;

final class MessageSent extends BaseEvent
{
    public function __construct(
        public readonly string $messageUuid,
        public readonly string $channel,
        public readonly string $driver,
        public readonly string $to,
        public readonly ?string $providerMessageId,
    ) {
        parent::__construct();
    }
}
