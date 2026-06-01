<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Drivers;

use Glueful\Extensions\Conversa\Drivers\TwilioDriver;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Glueful\Http\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwilioDriverTest extends TestCase
{
    private function client(MockResponse $r): Client
    {
        return new Client(new MockHttpClient($r), new NullLogger());
    }

    public function testSupportsBothChannels(): void
    {
        $d = new TwilioDriver($this->client(new MockResponse('{}')), ['enabled' => true, 'sid' => 'AC', 'token' => 'x', 'sms_from' => '+1'], []);
        $this->assertTrue($d->supports('sms'));
        $this->assertTrue($d->supports('whatsapp'));
    }

    public function testAvailabilityIsChannelAware(): void
    {
        // SMS sender configured, WhatsApp sender missing.
        $d = new TwilioDriver($this->client(new MockResponse('{}')), ['enabled' => true, 'sid' => 'AC', 'token' => 'x', 'sms_from' => '+15550000000'], []);
        $this->assertTrue($d->isAvailable('sms'));
        $this->assertFalse($d->isAvailable('whatsapp'));
    }

    public function testSendsSmsAndReturnsSid(): void
    {
        $resp = new MockResponse(json_encode(['sid' => 'SM123']), ['http_code' => 201]);
        $d = new TwilioDriver($this->client($resp), ['enabled' => true, 'sid' => 'AC', 'token' => 'x', 'sms_from' => '+15550000000'], []);

        $result = $d->send(OutboundMessage::text('sms', '+15551234567', 'hi'));
        $this->assertTrue($result->ok);
        $this->assertSame('SM123', $result->providerMessageId);
    }

    public function testWhatsappTemplateRequiresContentSid(): void
    {
        $d = new TwilioDriver($this->client(new MockResponse('{}')), ['enabled' => true, 'sid' => 'AC', 'token' => 'x', 'whatsapp_from' => 'whatsapp:+1'], []);

        $result = $d->send(OutboundMessage::template('whatsapp', '+15551234567', ['name' => 'order_shipped']));
        $this->assertFalse($result->ok);
        $this->assertNotNull($result->error);
    }
}
