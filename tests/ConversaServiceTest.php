<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests;

use Glueful\Database\Connection;
use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Extensions\Conversa\Database\Migrations\CreateConversaMessagesTable;
use Glueful\Extensions\Conversa\Drivers\DriverManager;
use Glueful\Extensions\Conversa\Drivers\LogDriver;
use Glueful\Extensions\Conversa\Events\MessageSent;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class ConversaServiceTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;
    private MessageRepository $repo;
    /** @var array<int,object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/conversa-svc-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
        (new CreateConversaMessagesTable())->up($this->connection->getSchemaBuilder());
        $this->repo = new MessageRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    private function service(array $features = []): ConversaService
    {
        $drivers = new DriverManager(
            ['sms' => 'log', 'whatsapp' => 'log'],
            ['log' => new LogDriver(new NullLogger())],
        );
        $sink = function (object $event): void {
            $this->events[] = $event;
        };

        return new ConversaService(
            $drivers,
            $this->repo,
            array_merge(['store_body' => true, 'max_retries' => 3], $features),
            $sink,
            new NullLogger(),
        );
    }

    public function testSendLogsRowAndDispatchesMessageSent(): void
    {
        $result = $this->service()->send('sms', '+15551234567', ['body' => 'hi']);

        $this->assertTrue($result->ok);
        $sent = array_filter($this->events, fn($e) => $e instanceof MessageSent);
        $this->assertCount(1, $sent);

        $rows = $this->connection->table('conversa_messages')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('sent', $rows[0]['status']);
        $this->assertSame('hi', $rows[0]['body']);
    }

    public function testRepeatIdempotencyKeyReturnsExistingRowWithoutSecondSend(): void
    {
        $svc = $this->service();
        $svc->send('sms', '+15551234567', ['body' => 'one'], ['idempotency_key' => 'k1']);
        $svc->send('sms', '+15551234567', ['body' => 'two'], ['idempotency_key' => 'k1']);

        $rows = $this->connection->table('conversa_messages')->get();
        $this->assertCount(1, $rows, 'second send with same key must not create/send again');
        $this->assertSame('one', $rows[0]['body']);
    }

    public function testTemplateRejectedOnSms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->send('sms', '+15551234567', ['template' => ['name' => 'x']]);
    }

    public function testRetryWithoutPayloadErrorsWhenStoreBodyOff(): void
    {
        $svc = $this->service(['store_body' => false]);
        $svc->send('sms', '+15551234567', ['body' => 'hi']);
        // With store_body off the body column is null; find the row uuid.
        $uuid = $this->connection->table('conversa_messages')->get()[0]['uuid'];

        $this->expectException(\RuntimeException::class);
        $svc->retry($uuid); // no payload, nothing to replay
    }

    public function testProviderResponseIsRedacted(): void
    {
        // A driver that echoes the recipient number in its raw response.
        // (LogDriver is final, so implement the interface directly.)
        $leaky = new class implements \Glueful\Extensions\Conversa\Drivers\ConversaDriver {
            public function getName(): string
            {
                return 'leaky';
            }
            public function supports(string $channel): bool
            {
                return true;
            }
            public function isAvailable(string $channel): bool
            {
                return true;
            }
            public function send(\Glueful\Extensions\Conversa\Support\OutboundMessage $m): \Glueful\Extensions\Conversa\Support\DriverResult
            {
                return \Glueful\Extensions\Conversa\Support\DriverResult::ok('pm_x', ['to' => $m->to, 'sid' => 'pm_x']);
            }
        };
        $drivers = new DriverManager(['sms' => 'leaky', 'whatsapp' => 'leaky'], ['leaky' => $leaky]);
        $svc = new ConversaService($drivers, $this->repo, ['store_body' => true, 'redact_provider_response' => true], fn(object $e) => null, new NullLogger());

        $svc->send('sms', '+15551234567', ['body' => 'hi']);

        $stored = (string) ($this->connection->table('conversa_messages')->get()[0]['provider_response'] ?? '');
        $this->assertStringNotContainsString('+15551234567', $stored);
        $this->assertStringContainsString('pm_x', $stored);
    }

    public function testRetryReusesSameRowAndIncrementsRetryCount(): void
    {
        $svc = $this->service();
        $svc->send('sms', '+15551234567', ['body' => 'hi']);
        $uuid = $this->connection->table('conversa_messages')->get()[0]['uuid'];

        $svc->retry($uuid);

        $rows = $this->connection->table('conversa_messages')->get();
        $this->assertCount(1, $rows, 'retry must not create a new row');
        $this->assertSame(1, (int) $rows[0]['retry_count']);
        $this->assertSame('sent', $rows[0]['status']);
    }
}
