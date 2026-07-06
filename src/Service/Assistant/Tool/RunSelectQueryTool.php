<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\Tool;

use Marcostastny\SuluAIBundle\Service\Assistant\AssistantToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\ConditionalToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryRunner;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\InvalidSelectQueryException;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\QueryResultCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\SelectQueryValidator;

/**
 * Non-terminal tool: runs a validated read-only SELECT and returns the rows
 * to the model so it can summarize or refine. When the model passes a title,
 * the result is additionally recorded as a queryResult action that the chat
 * renders as a table card with CSV export.
 */
class RunSelectQueryTool implements AssistantToolInterface, ConditionalToolInterface
{
    private const MAX_ROWS = 100;
    private const MAX_CELL_LENGTH = 300;
    private const MAX_CALLS = 5;

    public function __construct(
        private DataQueryGate $gate,
        private SelectQueryValidator $validator,
        private DataQueryRunner $runner,
        private QueryResultCollector $collector,
    ) {
    }

    public function getName(): string
    {
        return 'run_select_query';
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
                'name' => 'run_select_query',
                'description' => 'Run a read-only SQL SELECT against the tables from list_data_tables and get the rows back. Single SELECT statements only (no WITH/CTE, no schema-qualified names); results are capped at ' . self::MAX_ROWS . ' rows. Set "title" when the user wants to SEE the data - the result is then also shown to the user as a table with a CSV download.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => [
                            'type' => 'string',
                            'description' => 'The SELECT statement, MySQL dialect. Use JSON_EXTRACT(data, \'$.key\') for JSON columns.',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Short table headline in the user\'s language. Only set it when the user asked for a list/table of the data; omit for lookups you only need to answer in text.',
                        ],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        if ($this->collector->registerCall() > self::MAX_CALLS) {
            return ['error' => 'Query limit for this turn reached. Answer with the data you already have.'];
        }

        $sql = \trim((string) ($arguments['sql'] ?? ''));

        try {
            $validated = $this->validator->validate($sql, $this->gate->tables(), self::MAX_ROWS);
        } catch (InvalidSelectQueryException $e) {
            return ['error' => $e->getMessage()];
        }

        try {
            $result = $this->runner->run($validated, self::MAX_ROWS);
        } catch (\Throwable $e) {
            return ['error' => 'Query failed: ' . $e->getMessage()];
        }

        $rowCount = \count($result['rows']);

        $title = \trim((string) ($arguments['title'] ?? ''));
        if ('' !== $title) {
            $this->collector->add([
                'type' => 'queryResult',
                'title' => $title,
                'columns' => $result['columns'],
                'rows' => $result['rows'],
                'rowCount' => $rowCount,
                // The original SQL (without the injected chat LIMIT): the CSV
                // export endpoint revalidates it with its own, larger cap.
                'sql' => $sql,
            ]);
        }

        return [
            'columns' => $result['columns'],
            'rows' => \array_map(
                static fn (array $row): array => \array_map(
                    static fn (?string $value): ?string => null !== $value && \mb_strlen($value) > self::MAX_CELL_LENGTH
                        ? \mb_substr($value, 0, self::MAX_CELL_LENGTH) . ' ...[truncated]'
                        : $value,
                    $row
                ),
                $result['rows']
            ),
            'rowCount' => $rowCount,
        ];
    }
}
