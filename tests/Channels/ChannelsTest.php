<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Channels;

use Glueful\Database\Connection;
use Glueful\Extensions\Conversa\Channels\SmsChannel;
use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Extensions\Conversa\Database\Migrations\CreateConversaMessagesTable;
use Glueful\Extensions\Conversa\Drivers\DriverManager;
use Glueful\Extensions\Conversa\Drivers\LogDriver;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\RichNotificationChannel;
use Glueful\Notifications\Results\NotificationResult;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class ChannelsTest extends TestCase
{
    private function service(Connection $c): ConversaService
    {
        (new CreateConversaMessagesTable())->up($c->getSchemaBuilder());
        return new ConversaService(
            new DriverManager(['sms' => 'log', 'whatsapp' => 'log'], ['log' => new LogDriver(new NullLogger())]),
            new MessageRepository($c),
            ['store_body' => true],
            fn(object $e) => null,
            new NullLogger(),
        );
    }

    private function notifiable(string $phone): Notifiable
    {
        return new class ($phone) implements Notifiable {
            public function __construct(private string $phone)
            {
            }
            public function getNotifiableId(): string
            {
                return 'u1';
            }
            public function getNotifiableType(): string
            {
                return 'user';
            }
            public function routeNotificationFor(string $channel): ?string
            {
                return in_array($channel, ['sms', 'whatsapp'], true) ? $this->phone : null;
            }
            public function shouldReceiveNotification(string $type, string $channel): bool
            {
                return true;
            }
            public function getNotificationPreferences(): array
            {
                return [];
            }
        };
    }

    public function testSmsChannelSendsBodyAndReportsName(): void
    {
        $c = new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:'], 'pooling' => ['enabled' => false]]);
        $channel = new SmsChannel($this->service($c), available: true);

        $this->assertSame('sms', $channel->getChannelName());
        $this->assertTrue($channel->isAvailable());
        $this->assertTrue($channel->send($this->notifiable('+15551234567'), ['body' => 'hi']));
    }

    public function testSmsChannelIsRichAndReturnsStructuredSuccess(): void
    {
        $c = new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:'], 'pooling' => ['enabled' => false]]);
        $channel = new SmsChannel($this->service($c), available: true);

        $this->assertInstanceOf(RichNotificationChannel::class, $channel);

        $result = $channel->sendNotification($this->notifiable('+15551234567'), ['body' => 'hi']);

        $this->assertInstanceOf(NotificationResult::class, $result);
        $this->assertTrue($result->success);
        // LogDriver returns a "log_…" provider id and a {driver: log} raw response.
        $this->assertIsString($result->providerMessageId);
        $this->assertStringStartsWith('log_', (string) $result->providerMessageId);
        $this->assertSame(['driver' => 'log'], $result->metadata);
        $this->assertNotNull($result->latencyMs);
    }

    public function testSendNotificationFailsClosedWithoutRecipient(): void
    {
        $c = new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:'], 'pooling' => ['enabled' => false]]);
        $channel = new SmsChannel($this->service($c), available: true);

        $result = $channel->sendNotification($this->notifiable(''), ['body' => 'hi']);

        $this->assertFalse($result->success);
        $this->assertSame('no_recipient', $result->errorCode);
        $this->assertFalse($result->retryable);
    }

    public function testSmsChannelFailsWhenNoRecipientRouted(): void
    {
        $c = new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:'], 'pooling' => ['enabled' => false]]);
        $channel = new SmsChannel($this->service($c), available: true);

        $nobody = new class implements Notifiable {
            public function getNotifiableId(): string
            {
                return 'u2';
            }
            public function getNotifiableType(): string
            {
                return 'user';
            }
            public function routeNotificationFor(string $channel): ?string
            {
                return null;
            }
            public function shouldReceiveNotification(string $t, string $c): bool
            {
                return true;
            }
            public function getNotificationPreferences(): array
            {
                return [];
            }
        };

        $this->assertFalse($channel->send($nobody, ['body' => 'hi']));
    }
}
