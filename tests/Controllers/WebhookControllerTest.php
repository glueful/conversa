<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Controllers;

use Glueful\Extensions\Conversa\Events\MessageDelivered;
use Glueful\Extensions\Conversa\Webhooks\WhatsAppCloudStatusMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests the verify-and-map decision the controller delegates to, plus the
 * fail-closed contract, without booting the HTTP stack.
 */
final class WebhookControllerTest extends TestCase
{
    public function testInvalidSignatureProducesNoStatusUpdate(): void
    {
        $mapper = new WhatsAppCloudStatusMapper();
        $verified = $mapper->verify('{"x":1}', ['x-hub-signature-256' => 'sha256=bad'], 'https://app', 'secret');

        $this->assertFalse($verified, 'invalid signature must not verify (fail closed)');
    }

    public function testValidSignatureMapsDelivered(): void
    {
        $mapper = new WhatsAppCloudStatusMapper();
        $body = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [
            ['id' => 'wamid.9', 'status' => 'delivered'],
        ]]]]]]]);
        $sig = 'sha256=' . hash_hmac('sha256', $body, 'secret');

        $this->assertTrue($mapper->verify($body, ['x-hub-signature-256' => $sig], 'https://app', 'secret'));
        $mapped = $mapper->map(json_decode($body, true));
        $this->assertSame('delivered', $mapped['status']);
        $this->assertTrue(class_exists(MessageDelivered::class));
    }
}
