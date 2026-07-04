<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

class ToolRegistry
{
    /**
     * @var array<string, AssistantToolInterface>
     */
    private array $tools = [];

    /**
     * @param iterable<AssistantToolInterface> $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    public function get(string $name): ?AssistantToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDefinitions(): array
    {
        return \array_values(\array_map(
            static fn (AssistantToolInterface $tool) => $tool->getDefinition(),
            $this->tools
        ));
    }
}
