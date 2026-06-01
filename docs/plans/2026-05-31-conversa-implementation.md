# Conversa Extension Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Conversa Glueful extension — `sms`/`whatsapp` notification channels backed by swappable provider drivers (Twilio, Meta WhatsApp Cloud, log), with a message log, delivery webhooks, idempotent direct sends, and message-lifecycle events.

**Architecture:** Layered like the rest of Glueful — thin `NotificationChannel` adapters + HTTP controllers at the edges, one `ConversaService` send pipeline in the middle, `MessageRepository extends BaseRepository` for data, and `ConversaDriver` implementations per provider. Channels register with the framework `ChannelManager`; async rides the framework's `SendNotification` job; lifecycle `BaseEvent`s are emitted on send and webhook status changes.

**Tech Stack:** PHP 8.3+, Glueful Framework 1.49.0+ (needs the `auth_basic` passthrough + `whatsapp` queue type shipped in 1.49.0), PHPUnit 10.5, Symfony `MockHttpClient` for driver tests, SQLite (`:memory:`/temp file) for repository tests.

**Spec:** `extensions/conversa/docs/specs/2026-05-31-conversa-design.md` (read it first).

**Conventions used throughout:**
- Namespace `Glueful\Extensions\Conversa\` → `src/`; tests `Glueful\Extensions\Conversa\Tests\` → `tests/`.
- Run tests from the extension root: `vendor/bin/phpunit`.
- Commit after each task with no `Co-Authored-By` trailer.
- Work on the current branch (`main` for this fresh repo); do not create branches.

---

### Task 1: Package scaffold + test harness

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`
- Create: `.gitignore`

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "glueful/conversa",
    "description": "SMS & WhatsApp messaging channels for Glueful (Twilio, Meta WhatsApp Cloud)",
    "type": "glueful-extension",
    "license": "MIT",
    "authors": [
        { "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }
    ],
    "keywords": ["glueful", "notifications", "sms", "whatsapp", "twilio"],
    "require": {
        "php": "^8.3"
    },
    "require-dev": {
        "glueful/framework": "dev-main",
        "phpunit/phpunit": "^10.5",
        "squizlabs/php_codesniffer": "^3.6",
        "phpstan/phpstan": "^1.0"
    },
    "autoload": {
        "psr-4": { "Glueful\\Extensions\\Conversa\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Glueful\\Extensions\\Conversa\\Tests\\": "tests/" }
    },
    "scripts": {
        "test": "vendor/bin/phpunit",
        "phpcs": "vendor/bin/phpcs --standard=PSR12 src",
        "phpcbf": "vendor/bin/phpcbf --standard=PSR12 src",
        "analyze": "vendor/bin/phpstan analyse src --level=5"
    },
    "extra": {
        "glueful": {
            "name": "Conversa",
            "displayName": "Conversa (SMS & WhatsApp)",
            "description": "SMS & WhatsApp messaging channels for Glueful",
            "version": "0.1.0",
            "categories": ["notifications", "communication"],
            "publisher": "glueful-team",
            "provider": "Glueful\\Extensions\\Conversa\\ConversaServiceProvider",
            "requires": { "glueful": ">=1.49.0", "extensions": [] }
        }
    },
    "config": { "sort-packages": true }
}
```

- [ ] **Step 2: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Conversa">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Write `tests/bootstrap.php`**

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
```

- [ ] **Step 4: Write `.gitignore`**

```
/vendor/
/composer.lock
.phpunit.cache/
.DS_Store
```

- [ ] **Step 5: Install dependencies and confirm the harness runs**

Run: `composer install`
Then: `vendor/bin/phpunit`
Expected: PHPUnit runs and reports `No tests executed!` (exit 0) — the harness is wired and the framework is installed under `vendor/`.

- [ ] **Step 6: Commit**

```bash
git add composer.json phpunit.xml tests/bootstrap.php .gitignore
git commit -m "chore: scaffold Conversa package + PHPUnit harness"
```

---

### Task 2: Value objects — `OutboundMessage` and `DriverResult`

**Files:**
- Create: `src/Support/OutboundMessage.php`
- Create: `src/Support/DriverResult.php`
- Test: `tests/Support/OutboundMessageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Support;

use Glueful\Extensions\Conversa\Support\OutboundMessage;
use PHPUnit\Framework\TestCase;

final class OutboundMessageTest extends TestCase
{
    public function testTextMessageExposesBodyAndNoTemplate(): void
    {
        $m = OutboundMessage::text('sms', '+15551234567', 'hello', from: '+15550000000');

        $this->assertSame('sms', $m->channel);
        $this->assertSame('+15551234567', $m->to);
        $this->assertSame('hello', $m->body);
        $this->assertNull($m->template);
        $this->assertTrue($m->isText());
        $this->assertFalse($m->isTemplate());
    }

    public function testTemplateMessageExposesTemplateAndNoBody(): void
    {
        $m = OutboundMessage::template('whatsapp', '+15551234567', [
            'name' => 'order_shipped',
            'language' => 'en_US',
            'variables' => ['123'],
        ]);

        $this->assertSame('whatsapp', $m->channel);
        $this->assertNull($m->body);
        $this->assertSame('order_shipped', $m->template['name']);
        $this->assertTrue($m->isTemplate());
        $this->assertFalse($m->isText());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Support/OutboundMessageTest.php`
Expected: FAIL — class `OutboundMessage` not found.

- [ ] **Step 3: Write `src/Support/OutboundMessage.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Support;

/**
 * Immutable description of one outbound message. Exactly one of body/template is set.
 */
final class OutboundMessage
{
    /**
     * @param array{name:string,language?:string,variables?:array<int|string,mixed>,provider_ref?:string}|null $template
     * @param array<string,mixed> $meta
     */
    private function __construct(
        public readonly string $channel,
        public readonly string $to,
        public readonly ?string $body,
        public readonly ?array $template,
        public readonly ?string $from = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $meta = [],
        public readonly ?string $notifiableType = null,
        public readonly ?string $notifiableId = null,
    ) {
    }

    /** @param array<string,mixed> $meta */
    public static function text(
        string $channel,
        string $to,
        string $body,
        ?string $from = null,
        ?string $idempotencyKey = null,
        array $meta = [],
        ?string $notifiableType = null,
        ?string $notifiableId = null,
    ): self {
        return new self($channel, $to, $body, null, $from, $idempotencyKey, $meta, $notifiableType, $notifiableId);
    }

    /**
     * @param array{name:string,language?:string,variables?:array<int|string,mixed>,provider_ref?:string} $template
     * @param array<string,mixed> $meta
     */
    public static function template(
        string $channel,
        string $to,
        array $template,
        ?string $from = null,
        ?string $idempotencyKey = null,
        array $meta = [],
        ?string $notifiableType = null,
        ?string $notifiableId = null,
    ): self {
        return new self($channel, $to, null, $template, $from, $idempotencyKey, $meta, $notifiableType, $notifiableId);
    }

    public function isText(): bool
    {
        return $this->body !== null;
    }

    public function isTemplate(): bool
    {
        return $this->template !== null;
    }
}
```

- [ ] **Step 4: Write `src/Support/DriverResult.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Support;

/**
 * Outcome of a single driver send attempt.
 */
final class DriverResult
{
    /** @param array<string,mixed> $rawResponse */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $providerMessageId = null,
        public readonly array $rawResponse = [],
        public readonly ?string $error = null,
    ) {
    }

    /** @param array<string,mixed> $rawResponse */
    public static function ok(?string $providerMessageId, array $rawResponse = []): self
    {
        return new self(true, $providerMessageId, $rawResponse, null);
    }

    /** @param array<string,mixed> $rawResponse */
    public static function failed(string $error, array $rawResponse = []): self
    {
        return new self(false, null, $rawResponse, $error);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Support/OutboundMessageTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Support tests/Support
git commit -m "feat: add OutboundMessage and DriverResult value objects"
```

---

### Task 3: `ConversaDriver` interface + `LogDriver`

**Files:**
- Create: `src/Drivers/ConversaDriver.php`
- Create: `src/Drivers/LogDriver.php`
- Test: `tests/Drivers/LogDriverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Drivers;

use Glueful\Extensions\Conversa\Drivers\LogDriver;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class LogDriverTest extends TestCase
{
    public function testSupportsBothChannelsAndIsAlwaysAvailable(): void
    {
        $d = new LogDriver(new NullLogger());

        $this->assertSame('log', $d->getName());
        $this->assertTrue($d->supports('sms'));
        $this->assertTrue($d->supports('whatsapp'));
        $this->assertTrue($d->isAvailable('sms'));
        $this->assertTrue($d->isAvailable('whatsapp'));
    }

    public function testSendReturnsOkWithSyntheticId(): void
    {
        $d = new LogDriver(new NullLogger());
        $result = $d->send(OutboundMessage::text('sms', '+15551234567', 'hi'));

        $this->assertTrue($result->ok);
        $this->assertNotNull($result->providerMessageId);
        $this->assertStringStartsWith('log_', $result->providerMessageId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Drivers/LogDriverTest.php`
Expected: FAIL — class `LogDriver` not found.

- [ ] **Step 3: Write `src/Drivers/ConversaDriver.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;

interface ConversaDriver
{
    /** Driver key, e.g. 'twilio' | 'whatsapp_cloud' | 'log'. */
    public function getName(): string;

    /** Whether this driver can serve the given channel ('sms' | 'whatsapp'). */
    public function supports(string $channel): bool;

    /**
     * Whether the required configuration/credentials are present FOR THIS CHANNEL.
     * Channel-aware because a multi-channel driver (Twilio) may be configured for
     * SMS but not WhatsApp (missing whatsapp_from), or vice-versa.
     */
    public function isAvailable(string $channel): bool;

    public function send(OutboundMessage $message): DriverResult;
}
```

- [ ] **Step 4: Write `src/Drivers/LogDriver.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Psr\Log\LoggerInterface;

/**
 * Writes the message to the log instead of calling a provider. Safe default for
 * credential-free local/test runs. Never logs raw bodies/template variables.
 */
final class LogDriver implements ConversaDriver
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getName(): string
    {
        return 'log';
    }

    public function supports(string $channel): bool
    {
        return in_array($channel, ['sms', 'whatsapp'], true);
    }

    public function isAvailable(string $channel): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): DriverResult
    {
        $id = 'log_' . bin2hex(random_bytes(8));
        $this->logger->info('conversa.log_driver.send', [
            'channel' => $message->channel,
            'to' => $message->to,
            'kind' => $message->isTemplate() ? 'template' : 'text',
            'provider_message_id' => $id,
        ]);

        return DriverResult::ok($id, ['driver' => 'log']);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Drivers/LogDriverTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Drivers tests/Drivers
git commit -m "feat: add ConversaDriver interface and LogDriver"
```

---

### Task 4: `DriverManager`

**Files:**
- Create: `src/Drivers/DriverManager.php`
- Test: `tests/Drivers/DriverManagerTest.php`

The manager is constructed with the channel→driver map and the set of available driver instances keyed by name.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Drivers/DriverManagerTest.php`
Expected: FAIL — class `DriverManager` not found.

- [ ] **Step 3: Write `src/Drivers/DriverManager.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

/**
 * Resolves the configured ConversaDriver for a channel ('sms' | 'whatsapp').
 */
final class DriverManager
{
    /**
     * @param array<string,string> $default Channel => driver-key map
     * @param array<string,ConversaDriver> $drivers Driver-key => instance
     */
    public function __construct(
        private readonly array $default,
        private readonly array $drivers,
    ) {
    }

    public function driverFor(string $channel): ConversaDriver
    {
        $key = $this->default[$channel] ?? null;
        if ($key === null) {
            throw new \RuntimeException("Conversa: no driver configured for channel '{$channel}'.");
        }

        $driver = $this->drivers[$key] ?? null;
        if ($driver === null) {
            throw new \RuntimeException("Conversa: driver '{$key}' is not registered.");
        }

        if (!$driver->supports($channel)) {
            throw new \RuntimeException("Conversa: driver '{$key}' does not support channel '{$channel}'.");
        }

        return $driver;
    }

    public function available(string $channel): bool
    {
        $key = $this->default[$channel] ?? null;
        if ($key === null || !isset($this->drivers[$key])) {
            return false;
        }
        $driver = $this->drivers[$key];

        return $driver->supports($channel) && $driver->isAvailable($channel);
    }

    /** The configured driver key for a channel, without instantiating/validating it. */
    public function driverKeyFor(string $channel): string
    {
        return $this->default[$channel] ?? 'unknown';
    }
}
```

Add to the test (`tests/Drivers/DriverManagerTest.php`, inside `testResolvesConfiguredDriverForChannel`): `$this->assertSame('log', $m->driverKeyFor('sms'));`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Drivers/DriverManagerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Drivers/DriverManager.php tests/Drivers/DriverManagerTest.php
git commit -m "feat: add DriverManager for channel-to-driver resolution"
```

---

### Task 5: Migration — `conversa_messages`

**Files:**
- Create: `migrations/001_CreateConversaMessagesTable.php`
- Test: `tests/Database/CreateConversaMessagesTableTest.php`

The migration uses the framework's `SchemaBuilderInterface` (same pattern as `notiva/migrations/001_CreatePushDevicesTable.php`).

- [ ] **Step 1: Write the failing test (runs the migration against SQLite)**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Database/CreateConversaMessagesTableTest.php`
Expected: FAIL — class `CreateConversaMessagesTable` not found.

- [ ] **Step 3: Write `migrations/001_CreateConversaMessagesTable.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateConversaMessagesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('conversa_messages', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);

            $table->enum('channel', ['sms', 'whatsapp'], 'sms');
            $table->string('driver', 32);
            $table->string('to', 32);
            $table->string('from', 64)->nullable();

            // Content (free text and/or WhatsApp template); may be omitted when store_body=false.
            $table->text('body')->nullable();
            $table->string('template_name', 128)->nullable();
            $table->json('template_vars')->nullable();

            $table->string('idempotency_key', 128)->nullable();

            $table->enum('status', ['queued', 'sent', 'delivered', 'failed', 'undelivered'], 'queued');
            $table->string('provider_message_id', 255)->nullable();
            $table->json('provider_response')->nullable();
            $table->string('error', 500)->nullable();
            $table->integer('retry_count')->default(0);

            $table->string('notifiable_type', 100)->nullable();
            $table->string('notifiable_id', 255)->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            // Composite UNIQUE indexes give "unique where non-null" for free: in a
            // composite unique index a row with a NULL component compares as distinct
            // (SQLite / MySQL / PostgreSQL all allow multiple NULLs), so queued rows
            // with a NULL provider_message_id coexist while real provider ids stay
            // unique per driver, and multiple NULL idempotency_key rows coexist while
            // supplied keys are unique per channel. This is the race backstop behind
            // ConversaService's pre-send findByIdempotencyKey() check (a duplicate
            // concurrent send hits this constraint instead of double-charging).
            $table->unique(['driver', 'provider_message_id'], 'uniq_conversa_driver_pmid');
            $table->unique(['channel', 'idempotency_key'], 'uniq_conversa_idem');
            $table->index('status');
            $table->index('to');
            $table->index('created_at');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('conversa_messages');
    }

    public function getDescription(): string
    {
        return 'Create conversa_messages table (SMS/WhatsApp delivery log).';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Database/CreateConversaMessagesTableTest.php`
Expected: PASS (1 test). Verified: `SchemaBuilderInterface` exposes both `dropTable(string)` and `dropTableIfExists(string)`, and the column builder methods used here (`bigInteger`/`string`/`enum`/`text`/`json`/`integer`/`timestamp`/`default`/`nullable`/`primary`/`autoIncrement`/`unique`/`index`) match the working `notiva/migrations/001_CreatePushDevicesTable.php`.

- [ ] **Step 5: Commit**

```bash
git add migrations tests/Database/CreateConversaMessagesTableTest.php
git commit -m "feat: add conversa_messages migration"
```

---

### Task 6: `MessageRepository`

**Files:**
- Create: `src/Repositories/MessageRepository.php`
- Test: `tests/Repositories/MessageRepositoryTest.php`

`MessageRepository` extends `Glueful\Repository\BaseRepository` (abstract `getTableName()`, inherits `create()/update()/find()/findBy()/findWhere()`). Construct it with the test `Connection`.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Repositories/MessageRepositoryTest.php`
Expected: FAIL — class `MessageRepository` not found.

- [ ] **Step 3: Write `src/Repositories/MessageRepository.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Repositories;

use Glueful\Repository\BaseRepository;

final class MessageRepository extends BaseRepository
{
    public function getTableName(): string
    {
        return 'conversa_messages';
    }

    /**
     * Update the row matching (driver, provider_message_id) — used by webhooks.
     *
     * @param array<string,mixed> $raw Raw (already-redacted) provider payload
     */
    public function updateStatusByProviderId(
        string $driver,
        string $providerMessageId,
        string $status,
        array $raw = [],
    ): bool {
        $data = ['status' => $status];
        if ($status === 'delivered') {
            $data['delivered_at'] = date('Y-m-d H:i:s');
        }
        if ($raw !== []) {
            $data['provider_response'] = json_encode($raw, JSON_THROW_ON_ERROR);
        }

        $affected = $this->getConnection()->table($this->getTableName())
            ->where('driver', '=', $driver)
            ->where('provider_message_id', '=', $providerMessageId)
            ->update($data);

        return $affected > 0;
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(string $channel, string $key): ?array
    {
        $row = $this->getConnection()->table($this->getTableName())
            ->where('channel', '=', $channel)
            ->where('idempotency_key', '=', $key)
            ->first();

        return $row ?: null;
    }

    /**
     * Fetch the row a webhook refers to. Scoped by (driver, provider_message_id)
     * so two drivers cannot collide on the same provider id.
     *
     * @return array<string,mixed>|null
     */
    public function findByProviderMessageId(string $driver, string $providerMessageId): ?array
    {
        $row = $this->getConnection()->table($this->getTableName())
            ->where('driver', '=', $driver)
            ->where('provider_message_id', '=', $providerMessageId)
            ->first();

        return $row ?: null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Repositories/MessageRepositoryTest.php`
Expected: PASS (2 tests). Verified in `framework/src/Repository/BaseRepository.php`: the constructor sets `$this->table = $this->getTableName()` and stores a passed `Connection` as the shared connection; `create(array): string` generates the primary key (`uuid`, via `Utils::generateNanoID()`) and `created_at`/`updated_at` when absent, then returns the uuid. So overriding `getTableName()` only (as here) is sufficient, and the test must pass its `Connection` to the repository constructor (it does).

- [ ] **Step 5: Commit**

```bash
git add src/Repositories tests/Repositories
git commit -m "feat: add MessageRepository (status-by-provider-id, idempotency lookup)"
```

---

### Task 7: Lifecycle events

**Files:**
- Create: `src/Events/MessageSent.php`
- Create: `src/Events/MessageDelivered.php`
- Create: `src/Events/MessageFailed.php`
- Test: `tests/Events/MessageEventsTest.php`

Each extends `Glueful\Events\Contracts\BaseEvent` (must call `parent::__construct()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Events;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Conversa\Events\MessageFailed;
use Glueful\Extensions\Conversa\Events\MessageSent;
use PHPUnit\Framework\TestCase;

final class MessageEventsTest extends TestCase
{
    public function testMessageSentCarriesContextAndIsBaseEvent(): void
    {
        $e = new MessageSent('m_1', 'sms', 'log', '+15551234567', 'pm_1');

        $this->assertInstanceOf(BaseEvent::class, $e);
        $this->assertSame('m_1', $e->messageUuid);
        $this->assertSame('pm_1', $e->providerMessageId);
    }

    public function testMessageFailedCarriesReason(): void
    {
        $e = new MessageFailed('m_2', 'whatsapp', 'twilio', '+15551234567', 'driver_unavailable');

        $this->assertSame('driver_unavailable', $e->reason);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Events/MessageEventsTest.php`
Expected: FAIL — class `MessageSent` not found.

- [ ] **Step 3: Write the three event classes**

`src/Events/MessageSent.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Events;

use Glueful\Events\Contracts\BaseEvent;

final class MessageSent extends BaseEvent
{
    public function __construct(
        public readonly string $messageUuid,
        public readonly string $channel,
        public readonly string $driver,
        public readonly string $to,
        public readonly ?string $providerMessageId,
    ) {
        parent::__construct();
    }
}
```

`src/Events/MessageDelivered.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Events;

use Glueful\Events\Contracts\BaseEvent;

final class MessageDelivered extends BaseEvent
{
    public function __construct(
        public readonly string $messageUuid,
        public readonly string $channel,
        public readonly string $driver,
        public readonly ?string $providerMessageId,
    ) {
        parent::__construct();
    }
}
```

`src/Events/MessageFailed.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Events;

use Glueful\Events\Contracts\BaseEvent;

final class MessageFailed extends BaseEvent
{
    public function __construct(
        public readonly string $messageUuid,
        public readonly string $channel,
        public readonly string $driver,
        public readonly string $to,
        public readonly string $reason,
    ) {
        parent::__construct();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Events/MessageEventsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Events tests/Events
git commit -m "feat: add Conversa message lifecycle events"
```

---

### Task 8: `ConversaService` — the send pipeline

**Files:**
- Create: `src/ConversaService.php`
- Test: `tests/ConversaServiceTest.php`

`ConversaService` depends on `DriverManager`, `MessageRepository`, a `features` config array, and a PSR-14 dispatcher. To keep events assertable, accept a small callable sink: `(object $event): void`. We pass the framework `EventService` in production (its `dispatch()` matches), and a spy in tests.

> Signature note: `send(string $channel, string $to, array $payload, array $opts = [])`. `$payload` is `['body'=>...]` or `['template'=>[...]]`; `$opts` may carry `idempotency_key`, `meta`, `from`. `retry(string $messageUuid, ?array $payload = null)`.

- [ ] **Step 1: Write the failing test (integration: real DriverManager+LogDriver+sqlite repo, spy dispatcher)**

```php
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
            ['store_body' => true, 'max_retries' => 3] + $features,
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
        $result = $svc->send('sms', '+15551234567', ['body' => 'hi']);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ConversaServiceTest.php`
Expected: FAIL — class `ConversaService` not found.

- [ ] **Step 3: Write `src/ConversaService.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa;

use Glueful\Extensions\Conversa\Drivers\DriverManager;
use Glueful\Extensions\Conversa\Events\MessageFailed;
use Glueful\Extensions\Conversa\Events\MessageSent;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Psr\Log\LoggerInterface;

/**
 * The single synchronous send pipeline: validate -> idempotency check -> log row
 * (queued) -> resolve driver -> send -> record result -> dispatch event.
 */
final class ConversaService
{
    /** @var callable(object):void */
    private $dispatch;

    /**
     * @param array<string,mixed> $features
     * @param callable(object):void $dispatch PSR-14-style event sink (framework EventService::dispatch in prod)
     */
    public function __construct(
        private readonly DriverManager $drivers,
        private readonly MessageRepository $repository,
        private readonly array $features,
        callable $dispatch,
        private readonly LoggerInterface $logger,
    ) {
        $this->dispatch = $dispatch;
    }

    /**
     * @param array{body?:string,template?:array<string,mixed>} $payload
     * @param array{idempotency_key?:string,from?:string,meta?:array<string,mixed>} $opts
     */
    public function send(string $channel, string $to, array $payload, array $opts = []): DriverResult
    {
        $this->assertValidPayload($channel, $payload);

        $idemKey = $opts['idempotency_key'] ?? null;
        if ($idemKey !== null) {
            $existing = $this->repository->findByIdempotencyKey($channel, $idemKey);
            if ($existing !== null) {
                return DriverResult::ok($existing['provider_message_id'] ?? null, ['idempotent_replay' => true]);
            }
        }

        $message = $this->buildMessage($channel, $to, $payload, $opts);

        try {
            $uuid = $this->repository->create($this->rowForCreate($message));
        } catch (\Throwable $e) {
            // Lost an idempotency-key race: the unique (channel, idempotency_key)
            // index rejected the duplicate. Return the winner's row instead of
            // surfacing a DB error (spec: "repeat key returns existing message").
            if ($idemKey !== null) {
                $existing = $this->repository->findByIdempotencyKey($channel, $idemKey);
                if ($existing !== null) {
                    return DriverResult::ok($existing['provider_message_id'] ?? null, ['idempotent_replay' => true]);
                }
            }
            throw $e;
        }

        return $this->dispatchToDriver($uuid, $message);
    }

    public function retry(string $messageUuid, ?array $payload = null): DriverResult
    {
        $row = $this->repository->find($messageUuid);
        if ($row === null) {
            throw new \RuntimeException("Conversa: message '{$messageUuid}' not found.");
        }

        $channel = (string) $row['channel'];
        $to = (string) $row['to'];
        $payload ??= $this->reconstructPayload($row);
        if ($payload === null) {
            throw new \RuntimeException(
                "Conversa: cannot retry '{$messageUuid}' — payload was not stored (store_body=false); pass a fresh payload."
            );
        }

        // Retry re-sends against the SAME row (one row per logical message), bumping
        // retry_count — it does not create a new row.
        $this->repository->update($messageUuid, [
            'retry_count' => ((int) ($row['retry_count'] ?? 0)) + 1,
            'status' => 'queued',
            'error' => null,
        ]);

        return $this->dispatchToDriver($messageUuid, $this->buildMessage($channel, $to, $payload, []));
    }

    /** Resolve the driver, send, and record the outcome onto an existing row. */
    private function dispatchToDriver(string $uuid, OutboundMessage $message): DriverResult
    {
        $channel = $message->channel;
        $to = $message->to;

        if (!$this->drivers->available($channel)) {
            $this->repository->update($uuid, ['status' => 'failed', 'error' => 'driver_unavailable']);
            $this->emitFailed($uuid, $channel, $this->driverName($channel), $to, 'driver_unavailable');
            return DriverResult::failed('driver_unavailable');
        }

        $driver = $this->drivers->driverFor($channel);
        $result = $driver->send($message);

        if ($result->ok) {
            $this->repository->update($uuid, [
                'status' => 'sent',
                'provider_message_id' => $result->providerMessageId,
                'provider_response' => $this->encodeResponse($result->rawResponse),
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
            ($this->dispatch)(new MessageSent($uuid, $channel, $driver->getName(), $to, $result->providerMessageId));
        } else {
            $this->repository->update($uuid, [
                'status' => 'failed',
                'error' => $result->error,
                'provider_response' => $this->encodeResponse($result->rawResponse),
            ]);
            $this->emitFailed($uuid, $channel, $driver->getName(), $to, (string) $result->error);
        }

        return $result;
    }

    /** @param array{body?:string,template?:array<string,mixed>} $payload */
    private function assertValidPayload(string $channel, array $payload): void
    {
        $hasBody = isset($payload['body']) && $payload['body'] !== '';
        $hasTemplate = isset($payload['template']);

        if ($hasBody === $hasTemplate) {
            throw new \InvalidArgumentException('Provide exactly one of body / template.');
        }
        if ($hasTemplate && $channel !== 'whatsapp') {
            throw new \InvalidArgumentException("Templates are only valid on the 'whatsapp' channel.");
        }
    }

    /**
     * @param array{body?:string,template?:array<string,mixed>} $payload
     * @param array<string,mixed> $opts
     */
    private function buildMessage(string $channel, string $to, array $payload, array $opts): OutboundMessage
    {
        $from = $opts['from'] ?? null;
        $meta = $opts['meta'] ?? [];
        $idem = $opts['idempotency_key'] ?? null;

        if (isset($payload['template'])) {
            /** @var array{name:string,language?:string,variables?:array<int|string,mixed>,provider_ref?:string} $tpl */
            $tpl = $payload['template'];
            return OutboundMessage::template($channel, $to, $tpl, $from, $idem, $meta);
        }

        return OutboundMessage::text($channel, $to, (string) $payload['body'], $from, $idem, $meta);
    }

    /** @return array<string,mixed> */
    private function rowForCreate(OutboundMessage $m): array
    {
        $storeBody = (bool) ($this->features['store_body'] ?? true);

        $row = [
            'channel' => $m->channel,
            'driver' => $this->driverName($m->channel),
            'to' => $m->to,
            'from' => $m->from,
            'status' => 'queued',
            'idempotency_key' => $m->idempotencyKey,
        ];

        if ($storeBody) {
            $row['body'] = $m->body;
            if ($m->template !== null) {
                $row['template_name'] = $m->template['name'] ?? null;
                $row['template_vars'] = isset($m->template['variables'])
                    ? json_encode($m->template['variables'], JSON_THROW_ON_ERROR)
                    : null;
            }
        } elseif ($m->template !== null) {
            // Template name is low-sensitivity; keep it for audit even when bodies are off.
            $row['template_name'] = $m->template['name'] ?? null;
        }

        return $row;
    }

    /** @return array{body?:string,template?:array<string,mixed>}|null */
    private function reconstructPayload(array $row): ?array
    {
        if (($row['body'] ?? null) !== null && $row['body'] !== '') {
            return ['body' => (string) $row['body']];
        }
        if (($row['template_name'] ?? null) !== null) {
            $vars = isset($row['template_vars']) && $row['template_vars'] !== null
                ? json_decode((string) $row['template_vars'], true)
                : [];
            return ['template' => ['name' => (string) $row['template_name'], 'variables' => $vars ?? []]];
        }

        return null;
    }

    private function driverName(string $channel): string
    {
        return $this->drivers->driverKeyFor($channel);
    }

    /**
     * Redact PII (recipient numbers, message/template text) before persisting the
     * provider payload when features.redact_provider_response is true (default).
     *
     * @param array<string,mixed> $raw
     */
    private function encodeResponse(array $raw): ?string
    {
        if ($raw === []) {
            return null;
        }
        if ((bool) ($this->features['redact_provider_response'] ?? true)) {
            $raw = $this->redact($raw);
        }
        return json_encode($raw, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function redact(array $data): array
    {
        $sensitive = ['to', 'from', 'body', 'text', 'Body', 'To', 'From', 'ContentVariables', 'template'];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $sensitive, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }
        return $data;
    }

    private function emitFailed(string $uuid, string $channel, string $driver, string $to, string $reason): void
    {
        $this->logger->warning('conversa.send.failed', [
            'message_uuid' => $uuid, 'channel' => $channel, 'driver' => $driver, 'reason' => $reason,
        ]);
        ($this->dispatch)(new MessageFailed($uuid, $channel, $driver, $to, $reason));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/ConversaServiceTest.php`
Expected: PASS (6 tests: send+event, idempotency, template-rejected-on-sms, retry-without-payload-errors, provider-response-redacted, retry-reuses-same-row).

- [ ] **Step 5: Commit**

```bash
git add src/ConversaService.php tests/ConversaServiceTest.php
git commit -m "feat: add ConversaService send pipeline (idempotency, events, retry)"
```

---

### Task 9: Notification channels — `SmsChannel` and `WhatsAppChannel`

**Files:**
- Create: `src/Channels/SmsChannel.php`
- Create: `src/Channels/WhatsAppChannel.php`
- Test: `tests/Channels/ChannelsTest.php`

Both implement `Glueful\Notifications\Contracts\NotificationChannel`. They translate a `Notifiable` + data array into a `ConversaService::send()` call. Keep a shared base to avoid duplication.

- [ ] **Step 1: Write the failing test**

```php
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
```

> The `Notifiable` contract (verified in `framework/src/Notifications/Contracts/Notifiable.php`) is exactly these five methods: `routeNotificationFor(string $channel)`, `getNotifiableId(): string`, `getNotifiableType(): string`, `shouldReceiveNotification(string $type, string $channel): bool`, `getNotificationPreferences(): array`. The stubs above implement all five.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Channels/ChannelsTest.php`
Expected: FAIL — class `SmsChannel` not found.

- [ ] **Step 3: Write `src/Channels/AbstractConversaChannel.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Channels;

use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;

abstract class AbstractConversaChannel implements NotificationChannel
{
    public function __construct(
        protected readonly ConversaService $conversa,
        protected readonly bool $available,
    ) {
    }

    abstract public function getChannelName(): string;

    public function send(Notifiable $notifiable, array $data): bool
    {
        $to = $notifiable->routeNotificationFor($this->getChannelName());
        if (!is_string($to) || $to === '') {
            return false;
        }

        $payload = isset($data['template'])
            ? ['template' => $data['template']]
            : ['body' => (string) ($data['body'] ?? '')];

        $opts = [];
        if (isset($data['_meta']['delivery_idempotency_key'])) {
            $opts['idempotency_key'] = (string) $data['_meta']['delivery_idempotency_key'];
        }

        return $this->conversa->send($this->getChannelName(), $to, $payload, $opts)->ok;
    }

    public function format(array $data, Notifiable $notifiable): array
    {
        return $data;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getConfig(): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Write `src/Channels/SmsChannel.php` and `src/Channels/WhatsAppChannel.php`**

`src/Channels/SmsChannel.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Channels;

final class SmsChannel extends AbstractConversaChannel
{
    public function getChannelName(): string
    {
        return 'sms';
    }
}
```

`src/Channels/WhatsAppChannel.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Channels;

final class WhatsAppChannel extends AbstractConversaChannel
{
    public function getChannelName(): string
    {
        return 'whatsapp';
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Channels/ChannelsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Channels tests/Channels
git commit -m "feat: add sms/whatsapp NotificationChannel adapters"
```

---

### Task 10: Provider drivers — `TwilioDriver` and `WhatsAppCloudDriver`

**Files:**
- Create: `src/Drivers/TwilioDriver.php`
- Create: `src/Drivers/WhatsAppCloudDriver.php`
- Test: `tests/Drivers/TwilioDriverTest.php`
- Test: `tests/Drivers/WhatsAppCloudDriverTest.php`

Both call providers through `Glueful\Http\Client` (`post(url, options)` → `Glueful\Http\Response\Response`). Verified: `Client::__construct(HttpClientInterface $httpClient, LoggerInterface $logger, ?ApplicationContext $context = null)`, and `Response` exposes `getStatusCode()`, `json()`, `isSuccessful()`. `transformOptions()` supports `headers`, `auth_basic`, `query`, `json`, `form_params`, `body` (see the framework-side `auth_basic` passthrough in Task 14). Tests build the client directly with a Symfony `MockHttpClient`: `new Client(new MockHttpClient($response), new NullLogger())`.

- [ ] **Step 1: Write `tests/Drivers/WhatsAppCloudDriverTest.php` (failing)**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Drivers;

use Glueful\Extensions\Conversa\Drivers\WhatsAppCloudDriver;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Glueful\Http\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WhatsAppCloudDriverTest extends TestCase
{
    // Glueful\Http\Client::__construct(HttpClientInterface, LoggerInterface, ?ApplicationContext).
    private function client(MockResponse $response): Client
    {
        return new Client(new MockHttpClient($response), new NullLogger());
    }

    public function testUnavailableWhenCredentialsMissing(): void
    {
        $d = new WhatsAppCloudDriver($this->client(new MockResponse('{}')), ['enabled' => true], []);
        $this->assertFalse($d->isAvailable('whatsapp'));
    }

    public function testSendsTemplateAndReturnsProviderId(): void
    {
        $response = new MockResponse(
            json_encode(['messages' => [['id' => 'wamid.123']]]),
            ['http_code' => 200]
        );
        $config = ['enabled' => true, 'phone_id' => '999', 'token' => 'T'];
        $templates = ['order_shipped' => ['whatsapp_cloud' => ['name' => 'order_shipped', 'language' => 'en_US']]];

        $d = new WhatsAppCloudDriver($this->client($response), $config, $templates);

        $this->assertTrue($d->isAvailable('whatsapp'));
        $result = $d->send(OutboundMessage::template('whatsapp', '+15551234567', [
            'name' => 'order_shipped',
            'variables' => ['ABC'],
        ]));

        $this->assertTrue($result->ok);
        $this->assertSame('wamid.123', $result->providerMessageId);
    }

    public function testRejectsTemplateWithNoMapping(): void
    {
        $config = ['enabled' => true, 'phone_id' => '999', 'token' => 'T'];
        $d = new WhatsAppCloudDriver($this->client(new MockResponse('{}')), $config, []);

        $result = $d->send(OutboundMessage::template('whatsapp', '+15551234567', ['name' => 'unknown']));
        $this->assertFalse($result->ok);
        $this->assertNotNull($result->error);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Drivers/WhatsAppCloudDriverTest.php`
Expected: FAIL — class `WhatsAppCloudDriver` not found.

- [ ] **Step 3: Write `src/Drivers/WhatsAppCloudDriver.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Glueful\Http\Client;

final class WhatsAppCloudDriver implements ConversaDriver
{
    /**
     * @param array<string,mixed> $config Driver config (phone_id, token, ...)
     * @param array<string,array<string,mixed>> $templates Logical name => per-driver identity
     */
    public function __construct(
        private readonly Client $http,
        private readonly array $config,
        private readonly array $templates,
    ) {
    }

    public function getName(): string
    {
        return 'whatsapp_cloud';
    }

    public function supports(string $channel): bool
    {
        return $channel === 'whatsapp';
    }

    public function isAvailable(string $channel): bool
    {
        return $channel === 'whatsapp'
            && (bool) ($this->config['enabled'] ?? false)
            && ($this->config['phone_id'] ?? null) !== null
            && ($this->config['token'] ?? null) !== null;
    }

    public function send(OutboundMessage $message): DriverResult
    {
        $to = ltrim($message->to, '+');
        $body = ['messaging_product' => 'whatsapp', 'to' => $to];

        if ($message->isTemplate()) {
            $tpl = $message->template;
            $identity = $tpl['provider_ref'] ?? null
                ? ['name' => $tpl['provider_ref']]
                : ($this->templates[$tpl['name']]['whatsapp_cloud'] ?? null);

            if ($identity === null) {
                return DriverResult::failed("No whatsapp_cloud mapping for template '{$tpl['name']}'.");
            }

            $body['type'] = 'template';
            $body['template'] = [
                'name' => $identity['name'] ?? $tpl['name'],
                'language' => ['code' => $identity['language'] ?? ($tpl['language'] ?? 'en_US')],
                'components' => $this->components($tpl['variables'] ?? []),
            ];
        } else {
            $body['type'] = 'text';
            $body['text'] = ['body' => (string) $message->body];
        }

        try {
            $resp = $this->http->post(
                sprintf('https://graph.facebook.com/v19.0/%s/messages', $this->config['phone_id']),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $this->config['token']],
                    'json' => $body,
                ]
            );
            $data = $resp->json();
            if (!$resp->isSuccessful()) {
                return DriverResult::failed('whatsapp_cloud_http_' . $resp->getStatusCode(), is_array($data) ? $data : []);
            }
            $id = $data['messages'][0]['id'] ?? null;

            return DriverResult::ok($id, is_array($data) ? $data : []);
        } catch (\Throwable $e) {
            return DriverResult::failed('whatsapp_cloud_exception: ' . $e->getMessage());
        }
    }

    /**
     * @param array<int|string,mixed> $variables
     * @return array<int,array<string,mixed>>
     */
    private function components(array $variables): array
    {
        if ($variables === []) {
            return [];
        }
        $params = array_map(static fn($v) => ['type' => 'text', 'text' => (string) $v], array_values($variables));

        return [['type' => 'body', 'parameters' => $params]];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Drivers/WhatsAppCloudDriverTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Write `tests/Drivers/TwilioDriverTest.php` (failing)**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Tests\Drivers;

use Glueful\Extensions\Conversa\Drivers\TwilioDriver;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Glueful\Http\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwilioDriverTest extends TestCase
{
    private function client(MockResponse $r): Client
    {
        return new Client(new MockHttpClient($r), new NullLogger());
    }

    public function testSupportsBothChannels(): void
    {
        $d = new TwilioDriver($this->client(new MockResponse('{}')), ['enabled' => true, 'sid' => 'AC', 'token' => 'x', 'sms_from' => '+1'], []);
        $this->assertTrue($d->supports('sms'));
        $this->assertTrue($d->supports('whatsapp'));
    }

    public function testAvailabilityIsChannelAware(): void
    {
        // SMS sender configured, WhatsApp sender missing.
        $d = new TwilioDriver($this->client(new MockResponse('{}')), ['enabled' => true, 'sid' => 'AC', 'token' => 'x', 'sms_from' => '+15550000000'], []);
        $this->assertTrue($d->isAvailable('sms'));
        $this->assertFalse($d->isAvailable('whatsapp'));
    }

    public function testSendsSmsAndReturnsSid(): void
    {
        $resp = new MockResponse(json_encode(['sid' => 'SM123']), ['http_code' => 201]);
        $d = new TwilioDriver($this->client($resp), ['enabled' => true, 'sid' => 'AC', 'token' => 'x', 'sms_from' => '+15550000000'], []);

        $result = $d->send(OutboundMessage::text('sms', '+15551234567', 'hi'));
        $this->assertTrue($result->ok);
        $this->assertSame('SM123', $result->providerMessageId);
    }

    public function testWhatsappTemplateRequiresContentSid(): void
    {
        $d = new TwilioDriver($this->client(new MockResponse('{}')), ['enabled' => true, 'sid' => 'AC', 'token' => 'x', 'whatsapp_from' => 'whatsapp:+1'], []);

        $result = $d->send(OutboundMessage::template('whatsapp', '+15551234567', ['name' => 'order_shipped']));
        $this->assertFalse($result->ok);
        $this->assertNotNull($result->error);
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Drivers/TwilioDriverTest.php`
Expected: FAIL — class `TwilioDriver` not found.

- [ ] **Step 7: Write `src/Drivers/TwilioDriver.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Glueful\Http\Client;

final class TwilioDriver implements ConversaDriver
{
    /**
     * @param array<string,mixed> $config sid, token, sms_from, whatsapp_from
     * @param array<string,array<string,mixed>> $templates Logical name => per-driver identity
     */
    public function __construct(
        private readonly Client $http,
        private readonly array $config,
        private readonly array $templates,
    ) {
    }

    public function getName(): string
    {
        return 'twilio';
    }

    public function supports(string $channel): bool
    {
        return in_array($channel, ['sms', 'whatsapp'], true);
    }

    public function isAvailable(string $channel): bool
    {
        $base = (bool) ($this->config['enabled'] ?? false)
            && ($this->config['sid'] ?? null) !== null
            && ($this->config['token'] ?? null) !== null;
        if (!$base) {
            return false;
        }

        // Channel-aware: require the sender configured for THIS channel, so a
        // Twilio account set up for SMS only doesn't report the whatsapp channel
        // as available (which would fail at send time).
        return $channel === 'whatsapp'
            ? ($this->config['whatsapp_from'] ?? null) !== null
            : ($this->config['sms_from'] ?? null) !== null;
    }

    public function send(OutboundMessage $message): DriverResult
    {
        $isWhatsapp = $message->channel === 'whatsapp';
        $from = $isWhatsapp ? ($this->config['whatsapp_from'] ?? null) : ($this->config['sms_from'] ?? null);
        $to = $isWhatsapp ? 'whatsapp:' . $message->to : $message->to;

        if ($from === null) {
            return DriverResult::failed("Twilio: no 'from' configured for {$message->channel}.");
        }

        $form = ['From' => $from, 'To' => $to];

        if ($message->isTemplate()) {
            $tpl = $message->template;
            $contentSid = $tpl['provider_ref'] ?? ($this->templates[$tpl['name']]['twilio']['content_sid'] ?? null);
            if ($contentSid === null) {
                return DriverResult::failed("No twilio ContentSid mapping for template '{$tpl['name']}'.");
            }
            $form['ContentSid'] = $contentSid;
            $vars = $tpl['variables'] ?? [];
            if ($vars !== []) {
                // Twilio expects a JSON map of {"1":"x","2":"y"}.
                $map = [];
                foreach (array_values($vars) as $i => $v) {
                    $map[(string) ($i + 1)] = (string) $v;
                }
                $form['ContentVariables'] = json_encode($map, JSON_THROW_ON_ERROR);
            }
        } else {
            $form['Body'] = (string) $message->body;
        }

        try {
            $resp = $this->http->post(
                sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $this->config['sid']),
                [
                    // Glueful Http\Client now passes auth_basic through to Symfony
                    // HttpClient (framework change in this plan), so use the natural
                    // API; form_params becomes the url-encoded body + content-type.
                    'auth_basic' => [$this->config['sid'], $this->config['token']],
                    'form_params' => $form,
                ]
            );
            $data = $resp->json();
            if (!$resp->isSuccessful()) {
                return DriverResult::failed('twilio_http_' . $resp->getStatusCode(), is_array($data) ? $data : []);
            }

            return DriverResult::ok($data['sid'] ?? null, is_array($data) ? $data : []);
        } catch (\Throwable $e) {
            return DriverResult::failed('twilio_exception: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Drivers/`
Expected: PASS (LogDriver + DriverManager + Twilio + WhatsAppCloud).

- [ ] **Step 9: Commit**

```bash
git add src/Drivers/TwilioDriver.php src/Drivers/WhatsAppCloudDriver.php tests/Drivers/TwilioDriverTest.php tests/Drivers/WhatsAppCloudDriverTest.php
git commit -m "feat: add Twilio and WhatsApp Cloud drivers"
```

---

### Task 11: Webhook signature verification + status mappers

**Files:**
- Create: `src/Webhooks/StatusMapper.php` (interface)
- Create: `src/Webhooks/TwilioStatusMapper.php`
- Create: `src/Webhooks/WhatsAppCloudStatusMapper.php`
- Test: `tests/Webhooks/StatusMappersTest.php`

Each mapper does two things: `verify(rawBody, headers, fullUrl, secret): bool` (fail-closed) and `map(payload): array{provider_message_id:string,status:string}` returning canonical statuses (`delivered`/`failed`/`undelivered`/`sent`).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Webhooks/StatusMappersTest.php`
Expected: FAIL — class `WhatsAppCloudStatusMapper` not found.

- [ ] **Step 3: Write `src/Webhooks/StatusMapper.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Webhooks;

interface StatusMapper
{
    public function driverName(): string;

    /**
     * Verify provider authenticity. Fail closed: missing/invalid => false.
     *
     * @param array<string,string> $headers Lower-cased header name => value
     */
    public function verify(string $rawBody, array $headers, string $fullUrl, ?string $secret): bool;

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array{provider_message_id:string,status:string}>
     */
    public function mapAll(array $payload): array;

    /**
     * Convenience for a single status (first one).
     *
     * @param array<string,mixed> $payload
     * @return array{provider_message_id:string,status:string}|null
     */
    public function map(array $payload): ?array;
}
```

- [ ] **Step 4: Write `src/Webhooks/WhatsAppCloudStatusMapper.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Webhooks;

final class WhatsAppCloudStatusMapper implements StatusMapper
{
    private const STATUS_MAP = [
        'sent' => 'sent',
        'delivered' => 'delivered',
        'read' => 'delivered',
        'failed' => 'failed',
    ];

    public function driverName(): string
    {
        return 'whatsapp_cloud';
    }

    public function verify(string $rawBody, array $headers, string $fullUrl, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false; // configured to require a secret but none set => fail closed
        }
        $header = $headers['x-hub-signature-256'] ?? '';
        if ($header === '' || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }

    public function mapAll(array $payload): array
    {
        $out = [];
        $entries = $payload['entry'] ?? [];
        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $id = $status['id'] ?? null;
                    $raw = $status['status'] ?? null;
                    if ($id === null || $raw === null) {
                        continue;
                    }
                    $out[] = [
                        'provider_message_id' => (string) $id,
                        'status' => self::STATUS_MAP[$raw] ?? 'undelivered',
                    ];
                }
            }
        }

        return $out;
    }

    public function map(array $payload): ?array
    {
        return $this->mapAll($payload)[0] ?? null;
    }
}
```

- [ ] **Step 5: Write `src/Webhooks/TwilioStatusMapper.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Webhooks;

final class TwilioStatusMapper implements StatusMapper
{
    private const STATUS_MAP = [
        'queued' => 'sent',
        'sent' => 'sent',
        'delivered' => 'delivered',
        'undelivered' => 'undelivered',
        'failed' => 'failed',
    ];

    public function driverName(): string
    {
        return 'twilio';
    }

    public function verify(string $rawBody, array $headers, string $fullUrl, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false;
        }
        $sig = $headers['x-twilio-signature'] ?? '';
        if ($sig === '') {
            return false;
        }
        // Twilio: HMAC-SHA1 over the full external URL + sorted POST params, then base64.
        parse_str($rawBody, $params);
        ksort($params);
        $data = $fullUrl;
        foreach ($params as $k => $v) {
            $data .= $k . (is_array($v) ? implode('', $v) : (string) $v);
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $secret, true));

        return hash_equals($expected, $sig);
    }

    public function mapAll(array $payload): array
    {
        $id = $payload['MessageSid'] ?? $payload['SmsSid'] ?? null;
        $raw = $payload['MessageStatus'] ?? $payload['SmsStatus'] ?? null;
        if ($id === null || $raw === null) {
            return [];
        }

        return [[
            'provider_message_id' => (string) $id,
            'status' => self::STATUS_MAP[$raw] ?? 'undelivered',
        ]];
    }

    public function map(array $payload): ?array
    {
        return $this->mapAll($payload)[0] ?? null;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Webhooks/StatusMappersTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Webhooks tests/Webhooks
git commit -m "feat: add webhook signature verification + status mappers"
```

---

### Task 12: Controllers + routes

**Files:**
- Create: `src/Controllers/MessageController.php`
- Create: `src/Controllers/WebhookController.php`
- Create: `routes.php`
- Test: `tests/Controllers/WebhookControllerTest.php`

`WebhookController::handle` takes the matched provider, verifies (fail closed), maps statuses, and updates the repo + dispatches `MessageDelivered`/`MessageFailed`.

Controllers are **plain classes** (not `extends BaseController`) — this matches the working `notiva` `DeviceController`, and avoids `BaseController`'s required `ApplicationContext $context` constructor. They build responses with `Glueful\Http\Response` static factories (`success`, `validation`, `notFound`, `forbidden` — all verified in `framework/src/Http/Response.php`). The Meta verify handshake must return raw text, so it returns a plain `Symfony\Component\HttpFoundation\Response` (because `Glueful\Http\Response` extends `JsonResponse` and would JSON-encode the challenge). Controller actions receive a Symfony `Request` (same as notiva), reading the raw body via `$request->getContent()` and headers via `$request->headers`.

- [ ] **Step 1: Write `tests/Controllers/WebhookControllerTest.php` (failing)**

```php
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
```

- [ ] **Step 2: Run test to verify it fails appropriately**

Run: `vendor/bin/phpunit tests/Controllers/WebhookControllerTest.php`
Expected: PASS only after the controllers/events exist for the `class_exists` assertion; if `MessageDelivered` already exists (Task 7) this passes once the file is added. Run it now to confirm it currently passes the mapper assertions (the controller classes are exercised in Step 4 wiring).

- [ ] **Step 3: Write `src/Controllers/MessageController.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Controllers;

use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class MessageController
{
    public function __construct(
        private readonly ConversaService $conversa,
        private readonly MessageRepository $repository,
    ) {
    }

    public function store(Request $request): Response
    {
        /** @var array<string,mixed> $in */
        $in = json_decode((string) $request->getContent(), true) ?? [];

        $channel = (string) ($in['channel'] ?? '');
        $to = (string) ($in['to'] ?? '');
        if ($channel === '' || $to === '') {
            return Response::validation(['channel' => 'required', 'to' => 'required']);
        }

        $payload = isset($in['template']) ? ['template' => $in['template']] : ['body' => (string) ($in['body'] ?? '')];
        $opts = [];
        $idem = $request->headers->get('Idempotency-Key') ?? ($in['idempotency_key'] ?? null);
        if ($idem !== null) {
            $opts['idempotency_key'] = (string) $idem;
        }

        try {
            $result = $this->conversa->send($channel, $to, $payload, $opts);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['payload' => $e->getMessage()]);
        }

        return Response::success([
            'ok' => $result->ok,
            'provider_message_id' => $result->providerMessageId,
            'error' => $result->error,
        ], $result->ok ? 'Message accepted' : 'Send failed');
    }

    public function index(Request $request): Response
    {
        $conditions = [];
        foreach (['status', 'channel', 'to'] as $field) {
            $val = $request->query->get($field);
            if ($val !== null && $val !== '') {
                $conditions[$field] = $val;
            }
        }

        return Response::success($this->repository->findAll($conditions, ['created_at' => 'DESC'], 50));
    }
}
```

- [ ] **Step 4: Write `src/Controllers/WebhookController.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Controllers;

use Glueful\Extensions\Conversa\Events\MessageDelivered;
use Glueful\Extensions\Conversa\Events\MessageFailed;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Extensions\Conversa\Webhooks\StatusMapper;
use Glueful\Http\Response as ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebhookController
{
    /**
     * @param array<string,StatusMapper> $mappers driver-key => mapper
     * @param array<string,mixed> $driverConfig driver-key => config (for secrets)
     * @param callable(object):void $dispatch
     */
    /**
     * @param array<string,StatusMapper> $mappers
     * @param array<string,mixed> $driverConfig
     * @param callable(object):void $dispatch
     * @param string|null $webhookBaseUrl Public base URL (e.g. https://api.example.com) used
     *        to reconstruct the external callback URL Twilio signed, when running behind a
     *        proxy/load balancer. Null => use the request URI as-is.
     */
    public function __construct(
        private readonly array $mappers,
        private readonly array $driverConfig,
        private readonly MessageRepository $repository,
        private $dispatch,
        private readonly ?string $webhookBaseUrl = null,
    ) {
    }

    public function verify(Request $request, string $provider): Response
    {
        // Meta hub challenge handshake — must echo the raw challenge as text/plain.
        // Meta sends hub.mode / hub.verify_token / hub.challenge; depending on how the
        // request is constructed, dots may be preserved or mangled to underscores by
        // PHP query parsing, so accept both forms.
        $q = $request->query;
        $mode = $q->get('hub.mode') ?? $q->get('hub_mode');
        $token = $q->get('hub.verify_token') ?? $q->get('hub_verify_token');
        $challenge = (string) ($q->get('hub.challenge') ?? $q->get('hub_challenge') ?? '');
        $expected = $this->driverConfig[$provider]['verify_token'] ?? null;

        if ($mode === 'subscribe' && $expected !== null && hash_equals((string) $expected, (string) $token)) {
            return new Response($challenge, 200, ['Content-Type' => 'text/plain']);
        }

        return ApiResponse::forbidden('Invalid verify token');
    }

    public function handle(Request $request, string $provider): Response
    {
        $mapper = $this->mappers[$provider] ?? null;
        if ($mapper === null) {
            return ApiResponse::notFound('Unknown provider');
        }

        $raw = (string) $request->getContent();
        $headers = [];
        foreach ($request->headers->keys() as $key) {
            $headers[strtolower($key)] = (string) $request->headers->get($key);
        }
        $secret = $this->driverConfig[$provider]['app_secret']
            ?? $this->driverConfig[$provider]['token']
            ?? null;
        // Twilio signs the PUBLIC callback URL. Behind a proxy/LB the internal
        // request URI differs, so prefer a configured external base + request URI.
        $fullUrl = ($this->webhookBaseUrl !== null && $this->webhookBaseUrl !== '')
            ? rtrim($this->webhookBaseUrl, '/') . $request->getRequestUri()
            : $request->getUri();

        if (!$mapper->verify($raw, $headers, $fullUrl, $secret)) {
            return ApiResponse::forbidden('Invalid signature');
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            parse_str($raw, $payload); // Twilio posts form-encoded
        }

        foreach ($mapper->mapAll($payload) as $entry) {
            $this->repository->updateStatusByProviderId($provider, $entry['provider_message_id'], $entry['status']);
            // Fetch by (driver, id) — never by id alone — so a shared provider id
            // across drivers can't resolve to the wrong row.
            $row = $this->repository->findByProviderMessageId($provider, $entry['provider_message_id']);
            if ($row === null) {
                continue;
            }
            if ($entry['status'] === 'delivered') {
                ($this->dispatch)(new MessageDelivered($row['uuid'], $row['channel'], $provider, $entry['provider_message_id']));
            } elseif (in_array($entry['status'], ['failed', 'undelivered'], true)) {
                ($this->dispatch)(new MessageFailed($row['uuid'], $row['channel'], $provider, $row['to'], $entry['status']));
            }
        }

        return ApiResponse::success(['received' => true]);
    }
}
```

- [ ] **Step 5: Write `routes.php`**

```php
<?php

/**
 * Conversa routes. $router is provided by ServiceProvider::loadRoutesFrom().
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Conversa\Controllers\MessageController;
use Glueful\Extensions\Conversa\Controllers\WebhookController;

$router->group(['prefix' => '/conversa'], function ($router) {
    $router->post('/messages', [MessageController::class, 'store'])
        ->middleware(['auth', 'rate_limit:60,1']);
    $router->get('/messages', [MessageController::class, 'index'])
        ->middleware(['auth', 'rate_limit:100,1']);

    // Public, signature-verified inside the controller (fail closed).
    $router->get('/webhooks/{provider}', [WebhookController::class, 'verify']);
    $router->post('/webhooks/{provider}', [WebhookController::class, 'handle']);
});
```

- [ ] **Step 6: Run the controller test to verify it passes**

Run: `vendor/bin/phpunit tests/Controllers/WebhookControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Controllers routes.php tests/Controllers
git commit -m "feat: add message + webhook controllers and routes"
```

---

### Task 13: `ConversaServiceProvider` + `config/conversa.php`

**Files:**
- Create: `config/conversa.php`
- Create: `src/ConversaServiceProvider.php`
- Test: `tests/ConversaServiceProviderTest.php`

The provider wires services, merges config, and in `boot()` registers both channels with `ChannelManager`, loads migrations, and loads routes.

> **DI definition shape (verified in `framework/src/Container/Loader/DefaultServicesLoader.php`).** Supported keys are `class`, `shared`, `autowire`, `arguments` (with `'@id'` refs), `alias`, and `factory`. **A `factory` *Closure* is rejected in production** (`if ($prod && $spec['factory'] instanceof \Closure)` throws) — and Glueful extensions are compiled in production. So this provider uses only production-safe forms: `autowire` for services whose deps are themselves services, and a **`factory` given as `[self::class, 'methodName']`** (array form) for services that need config/HTTP-client/event-dispatch. The loader invokes that static method with the PSR container as its first argument (`$targetCallable($c)`). Creating a closure *inside* such a factory method (for the event-dispatch sink) is fine — the prod restriction only applies to closures placed directly in the `services()` array.

- [ ] **Step 1: Write `config/conversa.php`**

```php
<?php

return [
    'default' => [
        'sms'      => env('CONVERSA_SMS_DRIVER', 'log'),
        'whatsapp' => env('CONVERSA_WHATSAPP_DRIVER', 'log'),
    ],
    'drivers' => [
        'twilio' => [
            'enabled'       => (bool) env('CONVERSA_TWILIO_ENABLED', true),
            'sid'           => env('CONVERSA_TWILIO_SID'),
            'token'         => env('CONVERSA_TWILIO_TOKEN'),
            'sms_from'      => env('CONVERSA_TWILIO_SMS_FROM'),
            'whatsapp_from' => env('CONVERSA_TWILIO_WHATSAPP_FROM'),
        ],
        'whatsapp_cloud' => [
            'enabled'      => (bool) env('CONVERSA_WHATSAPP_ENABLED', true),
            'phone_id'     => env('CONVERSA_WHATSAPP_PHONE_ID'),
            'token'        => env('CONVERSA_WHATSAPP_TOKEN'),
            'verify_token' => env('CONVERSA_WHATSAPP_VERIFY_TOKEN'),
            'app_secret'   => env('CONVERSA_WHATSAPP_APP_SECRET'),
        ],
        'log' => ['enabled' => true],
    ],
    'templates' => [
        // 'order_shipped' => [
        //     'whatsapp_cloud' => ['name' => 'order_shipped', 'language' => 'en_US'],
        //     'twilio'         => ['content_sid' => 'HXxxxxxxxx'],
        // ],
    ],
    // Public base URL of this app (e.g. https://api.example.com), used to rebuild the
    // external callback URL Twilio signed when running behind a proxy/load balancer.
    'webhook_base_url' => env('CONVERSA_WEBHOOK_BASE_URL'),
    'features' => [
        'store_body'               => (bool) env('CONVERSA_STORE_BODY', true),
        'redact_provider_response' => (bool) env('CONVERSA_REDACT_PROVIDER_RESPONSE', true),
        'max_retries'              => (int) env('CONVERSA_MAX_RETRIES', 3),
        'log_messages'             => (bool) env('CONVERSA_LOG_MESSAGES', true),
    ],
];
```

- [ ] **Step 2: Write `tests/ConversaServiceProviderTest.php` (failing)**

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ConversaServiceProviderTest.php`
Expected: FAIL — class `ConversaServiceProvider` not found.

- [ ] **Step 4: Write `src/ConversaServiceProvider.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Conversa\Channels\SmsChannel;
use Glueful\Extensions\Conversa\Channels\WhatsAppChannel;
use Glueful\Extensions\Conversa\Controllers\WebhookController;
use Glueful\Extensions\Conversa\Drivers\DriverManager;
use Glueful\Extensions\Conversa\Drivers\LogDriver;
use Glueful\Extensions\Conversa\Drivers\TwilioDriver;
use Glueful\Extensions\Conversa\Drivers\WhatsAppCloudDriver;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Extensions\Conversa\Webhooks\TwilioStatusMapper;
use Glueful\Extensions\Conversa\Webhooks\WhatsAppCloudStatusMapper;
use Glueful\Extensions\ServiceProvider;
use Glueful\Http\Client;
use Glueful\Notifications\Services\ChannelManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ConversaServiceProvider extends ServiceProvider
{
    public function getName(): string
    {
        return 'Conversa';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function services(): array
    {
        return [
            // Need config + HTTP client => array static factory (prod-safe; receives $c).
            DriverManager::class => ['shared' => true, 'factory' => [self::class, 'makeDriverManager']],
            ConversaService::class => ['shared' => true, 'factory' => [self::class, 'makeConversaService']],
            SmsChannel::class => ['shared' => true, 'factory' => [self::class, 'makeSmsChannel']],
            WhatsAppChannel::class => ['shared' => true, 'factory' => [self::class, 'makeWhatsAppChannel']],
            WebhookController::class => ['shared' => true, 'factory' => [self::class, 'makeWebhookController']],

            // Deps are all services => autowire.
            MessageRepository::class => ['class' => MessageRepository::class, 'shared' => true, 'autowire' => true],
            Controllers\MessageController::class => [
                'class' => Controllers\MessageController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeDriverManager(ContainerInterface $c): DriverManager
    {
        $cfg = self::conversaConfig($c);
        $http = $c->get(Client::class);
        $logger = self::logger($c);
        $templates = $cfg['templates'] ?? [];

        $drivers = [
            'log' => new LogDriver($logger),
            'twilio' => new TwilioDriver($http, $cfg['drivers']['twilio'] ?? [], $templates),
            'whatsapp_cloud' => new WhatsAppCloudDriver($http, $cfg['drivers']['whatsapp_cloud'] ?? [], $templates),
        ];

        return new DriverManager($cfg['default'] ?? [], $drivers);
    }

    public static function makeConversaService(ContainerInterface $c): ConversaService
    {
        $cfg = self::conversaConfig($c);
        $events = $c->get(EventService::class);

        return new ConversaService(
            $c->get(DriverManager::class),
            $c->get(MessageRepository::class),
            $cfg['features'] ?? [],
            static fn(object $e) => $events->dispatch($e),
            self::logger($c),
        );
    }

    public static function makeSmsChannel(ContainerInterface $c): SmsChannel
    {
        return new SmsChannel($c->get(ConversaService::class), $c->get(DriverManager::class)->available('sms'));
    }

    public static function makeWhatsAppChannel(ContainerInterface $c): WhatsAppChannel
    {
        return new WhatsAppChannel($c->get(ConversaService::class), $c->get(DriverManager::class)->available('whatsapp'));
    }

    public static function makeWebhookController(ContainerInterface $c): WebhookController
    {
        $cfg = self::conversaConfig($c);
        $events = $c->get(EventService::class);

        return new WebhookController(
            ['twilio' => new TwilioStatusMapper(), 'whatsapp_cloud' => new WhatsAppCloudStatusMapper()],
            $cfg['drivers'] ?? [],
            $c->get(MessageRepository::class),
            static fn(object $e) => $events->dispatch($e),
            $cfg['webhook_base_url'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    private static function conversaConfig(ContainerInterface $c): array
    {
        return (array) config($c->get(ApplicationContext::class), 'conversa', []);
    }

    private static function logger(ContainerInterface $c): LoggerInterface
    {
        return $c->has(LoggerInterface::class) ? $c->get(LoggerInterface::class) : new NullLogger();
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('conversa', require __DIR__ . '/../config/conversa.php');
    }

    public function boot(ApplicationContext $context): void
    {
        if ($this->app->has(ChannelManager::class)) {
            $cm = $this->app->get(ChannelManager::class);
            $cm->registerChannel($this->app->get(SmsChannel::class));
            $cm->registerChannel($this->app->get(WhatsAppChannel::class));
        }

        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/ConversaServiceProviderTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: ALL PASS. Then `composer run phpcs` (PSR-12) clean.

- [ ] **Step 7: Commit**

```bash
git add src/ConversaServiceProvider.php config/conversa.php tests/ConversaServiceProviderTest.php
git commit -m "feat: add ConversaServiceProvider, config, and channel registration"
```

---

### Task 14: Framework changes (framework repo, not the extension)

Two small, additive, backward-compatible changes on the framework `dev` branch.

**14a — `auth_basic` passthrough in `Http\Client` (ALREADY DONE this session).**
`src/Http/Client.php::transformOptions()` previously mapped only `headers/query/json/form_params/body/...` and silently dropped `auth_basic`, so per-request Basic auth never reached Symfony HttpClient. Added an `auth_basic` passthrough (after the `headers` block) so `TwilioDriver` can use `'auth_basic' => [$sid, $token]` naturally. Test: `tests/Unit/Http/ClientTransformOptionsTest.php` (asserts `auth_basic` + `form_params` body + content-type pass through, and that no `auth_basic` key appears when not supplied). Status: implemented and green (`vendor/bin/phpunit tests/Unit/Http/ClientTransformOptionsTest.php`), phpcs + phpstan clean. If re-running from scratch, re-apply the 4-line passthrough and the test, then commit on `dev`.

**14b — allow `whatsapp` in `SendNotification` (ALREADY DONE this session).**
Added `'whatsapp'` to `SUPPORTED_TYPES` and a `'whatsapp' => 45` arm in the timeout
`match`; the validation `switch` has no `default`, so `whatsapp` falls through with
only the generic recipient/type checks. Test `tests/Unit/Queue/SendNotificationTypesTest.php`
asserts `whatsapp` (and the existing `sms`/`email`) are supported. Status: green;
phpcs + phpstan clean. If re-running from scratch, follow the steps below; commit on `dev`.

**Files:**
- Modify: `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Queue/Jobs/SendNotification.php`
- Test: `/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Unit/Queue/SendNotificationTypesTest.php` (or extend an existing job test)

This lets the framework notification job carry WhatsApp for async delivery.

- [ ] **Step 1: Write the failing test (in the framework repo)**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Queue\Jobs\SendNotification;
use PHPUnit\Framework\TestCase;

final class SendNotificationTypesTest extends TestCase
{
    public function testWhatsappIsASupportedType(): void
    {
        $ref = new \ReflectionClass(SendNotification::class);
        $supported = $ref->getConstant('SUPPORTED_TYPES');

        $this->assertContains('whatsapp', $supported);
        $this->assertContains('sms', $supported); // unchanged
    }
}
```

- [ ] **Step 2: Run it (from the framework repo) to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/framework && vendor/bin/phpunit tests/Unit/Queue/SendNotificationTypesTest.php`
Expected: FAIL — `whatsapp` not in `SUPPORTED_TYPES`.

- [ ] **Step 3: Add `whatsapp` to the constant and a timeout arm**

In `src/Queue/Jobs/SendNotification.php`, change:

```php
    private const SUPPORTED_TYPES = [
        'email',
        'sms',
        'push',
        'webhook',
        'slack',
        'discord'
    ];
```

to add `'whatsapp'`:

```php
    private const SUPPORTED_TYPES = [
        'email',
        'sms',
        'whatsapp',
        'push',
        'webhook',
        'slack',
        'discord'
    ];
```

And in the `getTimeout()`/`match` block (around line 201) add a `whatsapp` arm next to `sms`:

```php
        return match ($type) {
            'email' => 90,
            'sms' => 45,
            'whatsapp' => 45,
            'push' => 30,
            // ... keep existing arms / default unchanged
        };
```

(Open the file and place the `whatsapp` arm beside `sms`; do not remove other arms.)

- [ ] **Step 4: Run the framework test to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/framework && vendor/bin/phpunit tests/Unit/Queue/SendNotificationTypesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit (in the framework repo, on `dev`)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/framework
git add src/Queue/Jobs/SendNotification.php tests/Unit/Queue/SendNotificationTypesTest.php
git commit -m "feat(notifications): support 'whatsapp' type in SendNotification job"
```

---

### Task 15: Finalize — CHANGELOG + manual verification

**Files:**
- Create: `CHANGELOG.md`

- [ ] **Step 1: Write `CHANGELOG.md`**

```markdown
# Changelog

All notable changes to Conversa are documented here.

## [0.1.0] - 2026-05-31

### Added
- `sms` and `whatsapp` notification channels (registered with the framework `ChannelManager`).
- Provider drivers behind one interface: `twilio` (SMS + WhatsApp), `whatsapp_cloud` (WhatsApp), `log` (dev/test).
- WhatsApp template send (logical name → per-driver identity via `templates` config; Twilio `ContentSid`, Meta name+language).
- `ConversaService` send pipeline with caller-supplied idempotency and `retry()`.
- `conversa_messages` log + delivery-status webhooks (`/conversa/webhooks/{provider}`, fail-closed signature verification).
- Lifecycle events: `MessageSent`, `MessageDelivered`, `MessageFailed`.
- Privacy toggles: `store_body`, `redact_provider_response`.

### Requires
- Framework changes (Glueful `dev`): `auth_basic` passthrough in `Http\Client` (for Twilio Basic auth) and `whatsapp` added to `SendNotification::SUPPORTED_TYPES` (for async delivery).
```

- [ ] **Step 2: Manual verification in a host app (documented, not automated)**

In an app that has the extension installed:

```bash
composer require glueful/conversa
php glueful extensions:enable conversa
php glueful migrate run
php glueful extensions:list      # expect: conversa  enabled ✓
php glueful extensions:diagnose  # expect: no resolver errors; sms/whatsapp channels registered
```

With `CONVERSA_SMS_DRIVER=log`, send a test message and confirm a `conversa_messages` row is written with `status=sent`.

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: add Conversa 0.1.0 changelog"
```

---

## Self-Review

**Spec coverage:**
- `sms`/`whatsapp` channels + `ChannelManager` registration → Tasks 9, 13. ✅
- One `ConversaService` pipeline (direct + notification) → Task 8; channels call it → Task 9. ✅
- Drivers (`twilio`, `whatsapp_cloud`, `log`) behind `ConversaDriver` + `DriverManager` → Tasks 3, 4, 10. ✅
- WhatsApp template send + per-driver identity map + `provider_ref` override + reject-on-no-mapping → Task 10 (both drivers), config Task 13. ✅
- Delivery webhooks, fail-closed verification, status mappers → Tasks 11, 12. ✅
- `conversa_messages` log (body/template_name/template_vars/idempotency_key, composite index) → Task 5; repository → Task 6. ✅
- Idempotent direct sends → Task 8 (service) + Task 12 (controller header). ✅
- Lifecycle events → Task 7; emitted in service (Task 8) and webhook (Task 12). ✅
- Privacy toggles (`store_body`, `redact_provider_response`) → both in Task 8 (`store_body` in `rowForCreate()`, `redact_provider_response` in `encodeResponse()`/`redact()`), with a redaction test. ✅
- `retry()` semantics incl. `store_body=false` → Task 8 + test. ✅
- Framework changes (`auth_basic` passthrough + `whatsapp` in `SUPPORTED_TYPES`) → Task 14a/14b. ✅
- ServiceProvider (services/register/boot) → Task 13. ✅

**Framework APIs verified against source (not CLAUDE.md):** `Extensions\ServiceProvider` (`$this->app`, `loadRoutesFrom`/`loadMigrationsFrom`/`mergeConfig`); `DefaultServicesLoader` DSL (`autowire`/`arguments`/`alias`/`factory`; **closure factories rejected in prod** → array static factories used); `NotificationChannel` + `Notifiable` (5 methods incl. `getNotifiableType`); `ChannelManager::registerChannel`; `BaseRepository` (`getTableName` sets `$this->table`, `create()` generates uuid+timestamps, `find/findBy/findAll/update`); `QueryBuilder` (`where/get/first/insert/update`); `Connection` SQLite test config; `Http\Client::__construct(HttpClientInterface, LoggerInterface, ?ctx)` + `Response::getStatusCode/json/isSuccessful`; `Client::transformOptions()` supports `headers/query/json/form_params/body` and now `auth_basic` (added in Task 14a — it previously dropped it); `Glueful\Http\Response` statics (`success/validation/notFound/forbidden`) extends `JsonResponse`; `EventService::dispatch(object)`; `MigrationInterface` (`up/down/getDescription` + `dropTableIfExists`); controllers are plain classes (per `notiva\DeviceController`), not `BaseController`. `Queue\Jobs\SendNotification::SUPPORTED_TYPES` confirmed to lack `whatsapp` (Task 14b adds it).

**Type consistency:** `DriverResult::ok()/failed()`, `OutboundMessage::text()/template()`, `ConversaService::send($channel,$to,$payload,$opts)`/`retry($uuid,$payload=null)` (both route through the private `dispatchToDriver($uuid,$message)` so `retry()` reuses the same row), `DriverManager::driverFor()/available()/driverKeyFor()`, `MessageRepository::updateStatusByProviderId()/findByIdempotencyKey()/findByProviderMessageId()`, `StatusMapper::verify()/map()/mapAll()` are used consistently across tasks.

**Placeholder scan:** no TBD/TODO; every code step contains full code. All earlier "confirm against framework" hedges have been resolved by reading the source and updated to verified statements.
