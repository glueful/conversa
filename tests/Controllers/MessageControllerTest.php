<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Controllers;

use Glueful\Auth\UserIdentity;
use Glueful\Database\Connection;
use Glueful\Extensions\Conversa\Controllers\MessageController;
use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Extensions\Conversa\Database\Migrations\CreateConversaMessagesTable;
use Glueful\Extensions\Conversa\Drivers\DriverManager;
use Glueful\Extensions\Conversa\Drivers\LogDriver;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class MessageControllerTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;
    private MessageRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/conversa-ctrl-' . uniqid('', true) . '.sqlite';
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

    private function controller(): MessageController
    {
        $service = new ConversaService(
            new DriverManager(['sms' => 'log', 'whatsapp' => 'log'], ['log' => new LogDriver(new NullLogger())]),
            $this->repo,
            ['store_body' => true],
            fn(object $e) => null,
            new NullLogger(),
        );

        return new MessageController($service, $this->repo);
    }

    public function testIndexReturnsPaginatedShape(): void
    {
        foreach (['a', 'b', 'c'] as $body) {
            $this->repo->create([
                'channel' => 'sms',
                'driver' => 'log',
                'to' => '+15551234567',
                'status' => 'sent',
                'body' => $body,
            ]);
        }

        $request = Request::create('/conversa/messages?per_page=2&page=1', 'GET');
        $response = $this->controller()->index($request);

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['success']);
        $this->assertCount(2, $payload['data'], 'per_page=2 limits the page to 2 rows');
        $this->assertSame(3, $payload['total']);
        $this->assertSame(1, $payload['current_page']);
        $this->assertSame(2, $payload['per_page']);
        $this->assertEquals(2, $payload['total_pages']);
        $this->assertTrue($payload['has_next_page']);
    }

    public function testIndexFiltersByChannel(): void
    {
        $this->repo->create(['channel' => 'sms', 'driver' => 'log', 'to' => '+1', 'status' => 'sent', 'body' => 'x']);
        $this->repo->create(['channel' => 'whatsapp', 'driver' => 'log', 'to' => '+2', 'status' => 'sent', 'body' => 'y']);

        $request = Request::create('/conversa/messages?channel=whatsapp', 'GET');
        $payload = json_decode((string) $this->controller()->index($request)->getContent(), true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('whatsapp', $payload['data'][0]['channel']);
    }

    public function testStoreRejectsNonE164Recipient(): void
    {
        $request = Request::create('/conversa/messages', 'POST', [], [], [], [], json_encode([
            'channel' => 'sms',
            'to' => '5551234567',
            'body' => 'hi',
        ]));

        $response = $this->controller()->store($request);
        $payload = json_decode((string) $response->getContent(), true);

        $this->assertFalse($payload['success']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('to', $payload['error']['details']);
    }

    public function testHttpIdempotencyKeysAreScopedToAuthenticatedUser(): void
    {
        $first = Request::create('/conversa/messages', 'POST', [], [], [], [
            'HTTP_IDEMPOTENCY_KEY' => 'same-key',
        ], json_encode([
            'channel' => 'sms',
            'to' => '+15551234567',
            'body' => 'one',
        ]));
        $first->attributes->set('auth.user', new UserIdentity('user-a'));

        $second = Request::create('/conversa/messages', 'POST', [], [], [], [
            'HTTP_IDEMPOTENCY_KEY' => 'same-key',
        ], json_encode([
            'channel' => 'sms',
            'to' => '+15559876543',
            'body' => 'two',
        ]));
        $second->attributes->set('auth.user', new UserIdentity('user-b'));

        $this->controller()->store($first);
        $this->controller()->store($second);

        $rows = $this->connection->table('conversa_messages')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('one', $rows[0]['body']);
        $this->assertSame('two', $rows[1]['body']);
    }
}
