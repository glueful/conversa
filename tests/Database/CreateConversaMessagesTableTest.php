<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Database;

use Glueful\Database\Connection;
use Glueful\Extensions\Conversa\Database\Migrations\CreateConversaMessagesTable;
use PHPUnit\Framework\TestCase;

final class CreateConversaMessagesTableTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/conversa-mig-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testUpCreatesTableThatAcceptsAQueuedRow(): void
    {
        (new CreateConversaMessagesTable())->up($this->connection->getSchemaBuilder());

        $this->connection->table('conversa_messages')->insert([
            'uuid' => 'm_0000000001',
            'channel' => 'sms',
            'driver' => 'log',
            'to' => '+15551234567',
            'status' => 'queued',
        ]);

        $row = $this->connection->table('conversa_messages')
            ->where('uuid', '=', 'm_0000000001')->first();

        $this->assertNotNull($row);
        $this->assertSame('queued', $row['status']);
    }
}
