<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Drivers;

use Glueful\Extensions\Conversa\Drivers\LogDriver;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class LogDriverTest extends TestCase
{
    public function testSupportsBothChannelsAndIsAlwaysAvailable(): void
    {
        $d = new LogDriver(new NullLogger());

        $this->assertSame('log', $d->getName());
        $this->assertTrue($d->supports('sms'));
        $this->assertTrue($d->supports('whatsapp'));
        $this->assertTrue($d->isAvailable('sms'));
        $this->assertTrue($d->isAvailable('whatsapp'));
    }

    public function testSendReturnsOkWithSyntheticId(): void
    {
        $d = new LogDriver(new NullLogger());
        $result = $d->send(OutboundMessage::text('sms', '+15551234567', 'hi'));

        $this->assertTrue($result->ok);
        $this->assertNotNull($result->providerMessageId);
        $this->assertStringStartsWith('log_', $result->providerMessageId);
    }
}
