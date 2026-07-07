<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\AssistantToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\ConditionalToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\ToolRegistry;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    private function tool(string $name): AssistantToolInterface
    {
        return new class($name) implements AssistantToolInterface {
            public function __construct(private string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDefinition(): array
            {
                return ['type' => 'function', 'function' => ['name' => $this->name]];
            }

            public function execute(array $arguments): array
            {
                return [];
            }
        };
    }

    private function conditionalTool(string $name, bool $available): AssistantToolInterface
    {
        return new class($name, $available) implements AssistantToolInterface, ConditionalToolInterface {
            public function __construct(private string $name, private bool $available)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getDefinition(): array
            {
                return ['type' => 'function', 'function' => ['name' => $this->name]];
            }

            public function execute(array $arguments): array
            {
                return [];
            }
        };
    }

    public function testUnavailableConditionalToolsAreHidden(): void
    {
        $registry = new ToolRegistry([
            $this->tool('always'),
            $this->conditionalTool('gated_on', true),
            $this->conditionalTool('gated_off', false),
        ]);

        $names = \array_map(
            static fn (array $definition): string => $definition['function']['name'],
            $registry->getDefinitions()
        );
        $this->assertSame(['always', 'gated_on'], $names);

        $this->assertNotNull($registry->get('always'));
        $this->assertNotNull($registry->get('gated_on'));
        $this->assertNull($registry->get('gated_off'));
    }
}
