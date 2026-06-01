<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Webhooks;

use Glueful\Extensions\Conversa\Webhooks\WhatsAppCloudStatusMapper;
use PHPUnit\Framework\TestCase;

final class StatusMappersTest extends TestCase
{
    public function testMetaSignatureVerifiesAndFailsClosed(): void
    {
        $mapper = new WhatsAppCloudStatusMapper();
        $secret = 'app_secret';
        $body = '{"x":1}';
        $good = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue($mapper->verify($body, ['x-hub-signature-256' => $good], 'https://app/conversa/webhooks/whatsapp_cloud', $secret));
        $this->assertFalse($mapper->verify($body, ['x-hub-signature-256' => 'sha256=deadbeef'], 'https://app/...', $secret));
        $this->assertFalse($mapper->verify($body, [], 'https://app/...', $secret)); // missing => fail closed
    }

    public function testMetaMapsDeliveredStatus(): void
    {
        $mapper = new WhatsAppCloudStatusMapper();
        $payload = ['entry' => [['changes' => [['value' => ['statuses' => [
            ['id' => 'wamid.1', 'status' => 'delivered'],
        ]]]]]]];

        $mapped = $mapper->map($payload);
        $this->assertSame('wamid.1', $mapped['provider_message_id']);
        $this->assertSame('delivered', $mapped['status']);
    }
}
