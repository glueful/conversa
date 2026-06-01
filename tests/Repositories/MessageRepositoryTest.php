<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Repositories;

use Glueful\Database\Connection;
use Glueful\Extensions\Conversa\Database\Migrations\CreateConversaMessagesTable;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use PHPUnit\Framework\TestCase;

final class MessageRepositoryTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;
    private MessageRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/conversa-repo-' . uniqid('', true) . '.sqlite';
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

    public function testCreateThenUpdateStatusByProviderId(): void
    {
        $uuid = $this->repo->create([
            'channel' => 'sms',
            'driver' => 'log',
            'to' => '+15551234567',
            'status' => 'queued',
        ]);
        $this->assertNotSame('', $uuid);

        $this->repo->update($uuid, ['status' => 'sent', 'provider_message_id' => 'pm_1']);
        $this->repo->updateStatusByProviderId('log', 'pm_1', 'delivered');

        $row = $this->repo->find($uuid);
        $this->assertSame('delivered', $row['status']);

        // findByProviderMessageId is scoped by driver.
        $this->assertSame($uuid, $this->repo->findByProviderMessageId('log', 'pm_1')['uuid']);
        $this->assertNull($this->repo->findByProviderMessageId('twilio', 'pm_1'));
    }

    public function testFindByIdempotencyKeyScopedToChannel(): void
    {
        $this->repo->create([
            'channel' => 'sms',
            'driver' => 'log',
            'to' => '+15551234567',
            'status' => 'sent',
            'idempotency_key' => 'abc',
        ]);

        $hit = $this->repo->findByIdempotencyKey('sms', 'abc');
        $this->assertNotNull($hit);
        $this->assertSame('abc', $hit['idempotency_key']);

        $this->assertNull($this->repo->findByIdempotencyKey('whatsapp', 'abc'));
        $this->assertNull($this->repo->findByIdempotencyKey('sms', 'nope'));
    }
}
