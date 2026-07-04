<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

/**
 * A server-side tool the assistant model may call during its loop.
 * Implementations are collected via the "sulu_ai.assistant_tool" tag.
 */
interface AssistantToolInterface
{
    public function getName(): string;

    /**
     * OpenAI function definition, e.g. ["type" => "function", "function" => [...]].
     *
     * @return array<string, mixed>
     */
    public function getDefinition(): array;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> JSON-serializable result fed back to the model
     */
    public function execute(array $arguments): array;
}
