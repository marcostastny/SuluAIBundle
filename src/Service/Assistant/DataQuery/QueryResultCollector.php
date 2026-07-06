<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\DataQuery;

/**
 * Request-scoped sidechannel between the (non-terminal) run_select_query tool
 * and the chat response: titled query results are recorded here and merged
 * into the response actions by the AssistantController. Also counts tool
 * calls so a runaway model cannot query in a loop.
 */
class QueryResultCollector
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $actions = [];
    private int $calls = 0;

    public function reset(): void
    {
        $this->actions = [];
        $this->calls = 0;
    }

    public function registerCall(): int
    {
        return ++$this->calls;
    }

    /**
     * @param array<string, mixed> $action
     */
    public function add(array $action): void
    {
        $this->actions[] = $action;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->actions;
    }
}
