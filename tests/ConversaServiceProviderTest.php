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
        $this->assertArrayHasKey(\Glueful\Extensions\Conversa\Http\RequireConversaPermission::class, $services);
        $this->assertSame(
            ['conversa_permission'],
            $services[\Glueful\Extensions\Conversa\Http\RequireConversaPermission::class]['alias']
        );
    }

    public function testPermissionCatalogIsDeclared(): void
    {
        $provider = new ConversaServiceProvider(new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not needed');
            }

            public function has(string $id): bool
            {
                return false;
            }
        });

        $slugs = array_map(static fn($permission) => $permission->slug(), $provider->permissions());

        $this->assertSame(['conversa.messages.send', 'conversa.messages.read'], $slugs);
    }
}
