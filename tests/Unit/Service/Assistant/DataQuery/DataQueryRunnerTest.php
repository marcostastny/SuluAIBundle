<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\DataQuery;

use Doctrine\DBAL\Connection;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryRunner;
use PHPUnit\Framework\TestCase;

class DataQueryRunnerTest extends TestCase
{
    public function testRunsInsideReadOnlyTransactionAndNormalizesRows(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            }
        );
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 7, 'email' => 'a@b.c', 'note' => null],
            ['id' => 8, 'email' => 'x@y.z', 'note' => 'hi'],
        ]);

        $result = (new DataQueryRunner($connection))->run('SELECT ...', 100);

        $this->assertSame(['id', 'email', 'note'], $result['columns']);
        $this->assertSame([['7', 'a@b.c', null], ['8', 'x@y.z', 'hi']], $result['rows']);
        $this->assertContains('START TRANSACTION READ ONLY', $statements);
        $this->assertContains('ROLLBACK', $statements);
    }

    public function testRollsBackWhenTheQueryFails(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            }
        );
        $connection->method('fetchAllAssociative')->willThrowException(new \RuntimeException('boom'));

        try {
            (new DataQueryRunner($connection))->run('SELECT ...', 100);
            $this->fail('Expected the exception to bubble up.');
        } catch (\RuntimeException) {
        }

        $this->assertContains('ROLLBACK', $statements);
    }

    public function testCapsRowsBeyondMaxRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1], ['id' => 2], ['id' => 3],
        ]);

        $result = (new DataQueryRunner($connection))->run('SELECT ...', 2);

        $this->assertCount(2, $result['rows']);
    }
}
