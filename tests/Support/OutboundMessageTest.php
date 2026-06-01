<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Support;

use Glueful\Extensions\Conversa\Support\OutboundMessage;
use PHPUnit\Framework\TestCase;

final class OutboundMessageTest extends TestCase
{
    public function testTextMessageExposesBodyAndNoTemplate(): void
    {
        $m = OutboundMessage::text('sms', '+15551234567', 'hello', from: '+15550000000');

        $this->assertSame('sms', $m->channel);
        $this->assertSame('+15551234567', $m->to);
        $this->assertSame('hello', $m->body);
        $this->assertNull($m->template);
        $this->assertTrue($m->isText());
        $this->assertFalse($m->isTemplate());
    }

    public function testTemplateMessageExposesTemplateAndNoBody(): void
    {
        $m = OutboundMessage::template('whatsapp', '+15551234567', [
            'name' => 'order_shipped',
            'language' => 'en_US',
            'variables' => ['123'],
        ]);

        $this->assertSame('whatsapp', $m->channel);
        $this->assertNull($m->body);
        $this->assertSame('order_shipped', $m->template['name']);
        $this->assertTrue($m->isTemplate());
        $this->assertFalse($m->isText());
    }
}
