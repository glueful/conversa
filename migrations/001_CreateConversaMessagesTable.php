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
            $table->string('from_number', 64)->nullable();

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
