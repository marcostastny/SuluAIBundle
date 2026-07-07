<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\DataQuery;

use Doctrine\DBAL\Connection;

/**
 * Executes an already-validated SELECT inside a READ ONLY transaction with a
 * MySQL statement timeout. Memory stays bounded because the validator caps
 * LIMIT to at most the same $maxRows before the SQL reaches this class; the
 * array_slice is only a belt-and-braces guard.
 */
class DataQueryRunner
{
    private const TIMEOUT_MS = 2000;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string|null>>}
     *
     * @throws \Throwable on database errors (including the statement timeout)
     */
    public function run(string $sql, int $maxRows): array
    {
        try {
            $this->connection->executeStatement('SET SESSION MAX_EXECUTION_TIME = ' . self::TIMEOUT_MS);
        } catch (\Throwable) {
            // Not MySQL (or no privilege) — run without a statement timeout.
        }

        $this->connection->executeStatement('START TRANSACTION READ ONLY');
        try {
            $rows = $this->connection->fetchAllAssociative($sql);
        } finally {
            try {
                $this->connection->executeStatement('ROLLBACK');
            } catch (\Throwable) {
            }
            try {
                $this->connection->executeStatement('SET SESSION MAX_EXECUTION_TIME = 0');
            } catch (\Throwable) {
            }
        }

        $rows = \array_slice($rows, 0, $maxRows);

        return [
            'columns' => [] === $rows ? [] : \array_map('strval', \array_keys($rows[0])),
            'rows' => \array_map(
                static fn (array $row): array => \array_map(
                    static fn ($value): ?string => null === $value ? null : (string) $value,
                    \array_values($row)
                ),
                $rows
            ),
        ];
    }
}
