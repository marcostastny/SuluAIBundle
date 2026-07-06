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
        $tool = $this->tools[$name] ?? null;
        if ($tool instanceof ConditionalToolInterface && !$tool->isAvailable()) {
            return null;
        }

        return $tool;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDefinitions(): array
    {
        return \array_values(\array_map(
            static fn (AssistantToolInterface $tool) => $tool->getDefinition(),
            \array_filter(
                $this->tools,
                static fn (AssistantToolInterface $tool): bool => !$tool instanceof ConditionalToolInterface || $tool->isAvailable()
            )
        ));
    }
}
