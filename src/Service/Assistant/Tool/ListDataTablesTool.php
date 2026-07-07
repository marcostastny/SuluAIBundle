<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\Tool;

use Doctrine\DBAL\Connection;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\ConditionalToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;

/**
 * Describes the allowlisted tables (columns + MySQL types) so the model can
 * write correct SELECT queries instead of guessing the schema. Table names
 * are sanitized identifiers from the DataQueryGate, additionally quoted here.
 */
class ListDataTablesTool implements AssistantToolInterface, ConditionalToolInterface
{
    public function __construct(
        private DataQueryGate $gate,
        private Connection $connection,
    ) {
    }

    public function getName(): string
    {
        return 'list_data_tables';
    }

    public function isAvailable(): bool
    {
        return $this->gate->isAvailable();
    }

    public function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_data_tables',
                'description' => 'List the database tables you may query with run_select_query, with their columns and types. Always call this before writing SQL.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $tables = [];
        $unavailable = [];
        foreach ($this->gate->tables() as $table) {
            try {
                $columns = $this->connection->fetchAllAssociative(
                    'SHOW COLUMNS FROM ' . $this->connection->quoteIdentifier($table)
                );
            } catch (\Throwable) {
                $unavailable[] = $table;

                continue;
            }

            $tables[$table] = \array_map(
                static fn (array $column): array => [
                    'name' => (string) ($column['Field'] ?? ''),
                    'type' => (string) ($column['Type'] ?? ''),
                ],
                $columns
            );
        }

        $result = ['tables' => $tables];
        if ([] !== $unavailable) {
            $result['unavailable'] = $unavailable;
        }
        if (isset($tables['fo_dynamics'])) {
            $result['hints'] = 'fo_dynamics stores one form submission per row: "data" is a JSON object whose keys are the form\'s field keys (fo_form_fields.keyName); extract values with JSON_EXTRACT(data, \'$.email\') or data->>\'$.email\'. Find a form by title via fo_form_translations (columns "title", "locale", "idForms" = the form id) and filter fo_dynamics by formId. The latest submissions have the highest "created".';
        }

        return $result;
    }
}
