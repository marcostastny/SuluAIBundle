<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\DataQuery;

use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\QueryResultCollector;
use PHPUnit\Framework\TestCase;

class QueryResultCollectorTest extends TestCase
{
    public function testCollectsActionsAndCountsCalls(): void
    {
        $collector = new QueryResultCollector();

        $this->assertSame(1, $collector->registerCall());
        $this->assertSame(2, $collector->registerCall());

        $collector->add(['type' => 'queryResult', 'title' => 'Latest']);
        $this->assertSame([['type' => 'queryResult', 'title' => 'Latest']], $collector->all());

        $collector->reset();
        $this->assertSame([], $collector->all());
        $this->assertSame(1, $collector->registerCall());
    }
}
