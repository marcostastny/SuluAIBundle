<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\Tool;

use Doctrine\DBAL\Connection;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Marcostastny\SuluAIBundle\Service\Assistant\Tool\ListDataTablesTool;
use PHPUnit\Framework\TestCase;

class ListDataTablesToolTest extends TestCase
{
    private function tool(array $tables, Connection $connection): ListDataTablesTool
    {
        $gate = $this->createMock(DataQueryGate::class);
        $gate->method('tables')->willReturn($tables);
        $gate->method('isAvailable')->willReturn([] !== $tables);

        return new ListDataTablesTool($gate, $connection);
    }

    public function testDescribesAllowlistedTables(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql): array {
            if (\str_contains($sql, 'fo_dynamics')) {
                return [
                    ['Field' => 'id', 'Type' => 'int'],
                    ['Field' => 'data', 'Type' => 'longtext'],
                ];
            }

            throw new \RuntimeException('missing table');
        });

        $result = $this->tool(['fo_dynamics', 'gone_table'], $connection)->execute([]);

        $this->assertSame(
            [['name' => 'id', 'type' => 'int'], ['name' => 'data', 'type' => 'longtext']],
            $result['tables']['fo_dynamics']
        );
        $this->assertSame(['gone_table'], $result['unavailable']);
        $this->assertStringContainsString('JSON_EXTRACT', $result['hints']);
    }

    public function testNoHintsWithoutDynamicsTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        $connection->method('fetchAllAssociative')->willReturn([['Field' => 'id', 'Type' => 'int']]);

        $result = $this->tool(['fo_forms'], $connection)->execute([]);

        $this->assertArrayNotHasKey('hints', $result);
        $this->assertArrayNotHasKey('unavailable', $result);
    }

    public function testAvailabilityFollowsGate(): void
    {
        $connection = $this->createMock(Connection::class);

        $this->assertTrue($this->tool(['fo_forms'], $connection)->isAvailable());
        $this->assertFalse($this->tool([], $connection)->isAvailable());
        $this->assertSame('list_data_tables', $this->tool([], $connection)->getName());
    }
}
