<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\Tool;

use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryRunner;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\QueryResultCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\SelectQueryValidator;
use Marcostastny\SuluAIBundle\Service\Assistant\Tool\RunSelectQueryTool;
use PHPUnit\Framework\TestCase;

class RunSelectQueryToolTest extends TestCase
{
    private QueryResultCollector $collector;

    private function tool(?DataQueryRunner $runner = null): RunSelectQueryTool
    {
        $gate = $this->createMock(DataQueryGate::class);
        $gate->method('tables')->willReturn(['fo_dynamics']);
        $gate->method('isAvailable')->willReturn(true);

        if (null === $runner) {
            $runner = $this->createMock(DataQueryRunner::class);
            $runner->method('run')->willReturn([
                'columns' => ['id', 'data'],
                'rows' => [['1', \str_repeat('x', 400)]],
            ]);
        }

        $this->collector = new QueryResultCollector();

        return new RunSelectQueryTool($gate, new SelectQueryValidator(), $runner, $this->collector);
    }

    public function testExecutesAndTruncatesCellsForTheModel(): void
    {
        $result = $this->tool()->execute(['sql' => 'SELECT id, data FROM fo_dynamics']);

        $this->assertSame(['id', 'data'], $result['columns']);
        $this->assertSame(1, $result['rowCount']);
        $this->assertSame(300 + \mb_strlen(' ...[truncated]'), \mb_strlen($result['rows'][0][1]));
        $this->assertSame([], $this->collector->all());
    }

    public function testTitleRecordsQueryResultActionWithOriginalSql(): void
    {
        $result = $this->tool()->execute([
            'sql' => 'SELECT id, data FROM fo_dynamics',
            'title' => 'Latest reservations',
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $actions = $this->collector->all();
        $this->assertCount(1, $actions);
        $this->assertSame('queryResult', $actions[0]['type']);
        $this->assertSame('Latest reservations', $actions[0]['title']);
        // Original SQL (no injected LIMIT): export applies its own cap.
        $this->assertSame('SELECT id, data FROM fo_dynamics', $actions[0]['sql']);
        $this->assertSame(1, $actions[0]['rowCount']);
        // Card rows are untruncated.
        $this->assertSame(400, \mb_strlen($actions[0]['rows'][0][1]));
    }

    public function testInvalidSqlReturnsErrorWithoutRunning(): void
    {
        $runner = $this->createMock(DataQueryRunner::class);
        $runner->expects($this->never())->method('run');

        $result = $this->tool($runner)->execute(['sql' => 'DELETE FROM fo_dynamics']);

        $this->assertArrayHasKey('error', $result);
    }

    public function testDatabaseErrorReturnsError(): void
    {
        $runner = $this->createMock(DataQueryRunner::class);
        $runner->method('run')->willThrowException(new \RuntimeException('Unknown column "nope"'));

        $result = $this->tool($runner)->execute(['sql' => 'SELECT nope FROM fo_dynamics']);

        $this->assertStringContainsString('Unknown column', $result['error']);
    }

    public function testCallLimitPerTurn(): void
    {
        $tool = $this->tool();
        for ($i = 0; $i < 5; ++$i) {
            $this->assertArrayNotHasKey('error', $tool->execute(['sql' => 'SELECT id FROM fo_dynamics']));
        }

        $result = $tool->execute(['sql' => 'SELECT id FROM fo_dynamics']);

        $this->assertStringContainsString('limit', \strtolower($result['error']));
    }
}
