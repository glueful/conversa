<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Drivers;

use Glueful\Extensions\Conversa\Drivers\ConversaDriver;
use Glueful\Extensions\Conversa\Drivers\DriverManager;
use Glueful\Extensions\Conversa\Drivers\LogDriver;
use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class DriverManagerTest extends TestCase
{
    private function manager(array $default): DriverManager
    {
        return new DriverManager($default, ['log' => new LogDriver(new NullLogger())]);
    }

    public function testResolvesConfiguredDriverForChannel(): void
    {
        $m = $this->manager(['sms' => 'log', 'whatsapp' => 'log']);

        $this->assertSame('log', $m->driverFor('sms')->getName());
        $this->assertTrue($m->available('sms'));
        $this->assertSame('log', $m->driverKeyFor('sms'));
    }

    public function testThrowsWhenDriverNotRegistered(): void
    {
        $m = $this->manager(['sms' => 'twilio', 'whatsapp' => 'log']);

        $this->expectException(\RuntimeException::class);
        $m->driverFor('sms');
    }

    public function testThrowsWhenDriverDoesNotSupportChannel(): void
    {
        // A stub driver that only supports sms, registered under 'whatsapp'.
        // (LogDriver is final, so implement the interface directly.)
        $smsOnly = new class implements ConversaDriver {
            public function getName(): string
            {
                return 'sms_only';
            }
            public function supports(string $channel): bool
            {
                return $channel === 'sms';
            }
            public function isAvailable(string $channel): bool
            {
                return true;
            }
            public function send(OutboundMessage $message): DriverResult
            {
                return DriverResult::ok('x');
            }
        };

        $m = new DriverManager(['whatsapp' => 'sms_only'], ['sms_only' => $smsOnly]);

        $this->expectException(\RuntimeException::class);
        $m->driverFor('whatsapp');
    }
}
