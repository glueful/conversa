<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Events;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Conversa\Events\MessageFailed;
use Glueful\Extensions\Conversa\Events\MessageSent;
use PHPUnit\Framework\TestCase;

final class MessageEventsTest extends TestCase
{
    public function testMessageSentCarriesContextAndIsBaseEvent(): void
    {
        $e = new MessageSent('m_1', 'sms', 'log', '+15551234567', 'pm_1');

        $this->assertInstanceOf(BaseEvent::class, $e);
        $this->assertSame('m_1', $e->messageUuid);
        $this->assertSame('pm_1', $e->providerMessageId);
    }

    public function testMessageFailedCarriesReason(): void
    {
        $e = new MessageFailed('m_2', 'whatsapp', 'twilio', '+15551234567', 'driver_unavailable');

        $this->assertSame('driver_unavailable', $e->reason);
    }
}
