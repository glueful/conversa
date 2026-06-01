<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests;

use Glueful\Extensions\Conversa\ConversaServiceProvider;
use PHPUnit\Framework\TestCase;

final class ConversaServiceProviderTest extends TestCase
{
    public function testServicesAreDeclared(): void
    {
        $services = ConversaServiceProvider::services();

        $this->assertArrayHasKey(\Glueful\Extensions\Conversa\ConversaService::class, $services);
        $this->assertArrayHasKey(\Glueful\Extensions\Conversa\Channels\SmsChannel::class, $services);
        $this->assertArrayHasKey(\Glueful\Extensions\Conversa\Channels\WhatsAppChannel::class, $services);
        $this->assertArrayHasKey(\Glueful\Extensions\Conversa\Drivers\DriverManager::class, $services);
    }
}
