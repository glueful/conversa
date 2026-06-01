<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Drivers;

use Glueful\Extensions\Conversa\Drivers\WhatsAppCloudDriver;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Glueful\Http\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WhatsAppCloudDriverTest extends TestCase
{
    // Glueful\Http\Client::__construct(HttpClientInterface, LoggerInterface, ?ApplicationContext).
    private function client(MockResponse $response): Client
    {
        return new Client(new MockHttpClient($response), new NullLogger());
    }

    public function testUnavailableWhenCredentialsMissing(): void
    {
        $d = new WhatsAppCloudDriver($this->client(new MockResponse('{}')), ['enabled' => true], []);
        $this->assertFalse($d->isAvailable('whatsapp'));
    }

    public function testSendsTemplateAndReturnsProviderId(): void
    {
        $response = new MockResponse(
            json_encode(['messages' => [['id' => 'wamid.123']]]),
            ['http_code' => 200]
        );
        $config = ['enabled' => true, 'phone_id' => '999', 'token' => 'T'];
        $templates = ['order_shipped' => ['whatsapp_cloud' => ['name' => 'order_shipped', 'language' => 'en_US']]];

        $d = new WhatsAppCloudDriver($this->client($response), $config, $templates);

        $this->assertTrue($d->isAvailable('whatsapp'));
        $result = $d->send(OutboundMessage::template('whatsapp', '+15551234567', [
            'name' => 'order_shipped',
            'variables' => ['ABC'],
        ]));

        $this->assertTrue($result->ok);
        $this->assertSame('wamid.123', $result->providerMessageId);
    }

    public function testRejectsTemplateWithNoMapping(): void
    {
        $config = ['enabled' => true, 'phone_id' => '999', 'token' => 'T'];
        $d = new WhatsAppCloudDriver($this->client(new MockResponse('{}')), $config, []);

        $result = $d->send(OutboundMessage::template('whatsapp', '+15551234567', ['name' => 'unknown']));
        $this->assertFalse($result->ok);
        $this->assertNotNull($result->error);
    }
}
