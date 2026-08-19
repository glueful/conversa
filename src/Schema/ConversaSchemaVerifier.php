<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/conversa (schema policy spec B7): each create migration proves
 * every table it creates with its load-bearing columns. Unknown basenames are never adoptable.
 */
final class ConversaSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/conversa';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreateConversaMessagesTable.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreateConversaMessagesTable.php' => $this->tablesWithColumns($db, [
                'conversa_messages' => ['driver', 'to', 'body', 'provider_message_id'],
            ]),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }
}
