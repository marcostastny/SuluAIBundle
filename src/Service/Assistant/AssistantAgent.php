<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\OpenAiClient;

/**
 * Runs the OpenAI-compatible function-calling loop for the assistant.
 * Server tools from the ToolRegistry are executed in-loop; propose_edits and
 * propose_navigation are terminal and returned to the client as actions.
 */
class AssistantAgent
{
    private const MAX_ITERATIONS = 5;

    public function __construct(
        private OpenAiClient $client,
        private ToolRegistry $toolRegistry,
        private NavigationTargetCollector $targetCollector,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param (callable(array<int, mixed>): list<string>)|null $validateOps returns error strings, empty when
     *                                                                      valid; null disables propose_edits
     *
     * @return array{reply: string, actions: list<array<string, mixed>>}
     */
    public function run(
        string $apiUrl,
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        ?callable $validateOps
    ): array {
        $this->targetCollector->reset();

        $conversation = [['role' => 'system', 'content' => $systemPrompt], ...$messages];
        $tools = [$this->proposeNavigationDefinition(), ...$this->toolRegistry->getDefinitions()];
        if (null !== $validateOps) {
            $tools = [$this->proposeEditsDefinition(), ...$tools];
        }
        $proposalRetried = false;
        $navigationRetried = false;

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; ++$iteration) {
            $message = $this->requestCompletion($apiUrl, $apiKey, $model, $conversation, $tools);
            $toolCalls = $message['tool_calls'] ?? [];

            if (!\is_array($toolCalls) || [] === $toolCalls) {
                return ['reply' => \trim((string) ($message['content'] ?? '')), 'actions' => []];
            }

            $conversation[] = $message;

            foreach ($toolCalls as $toolCall) {
                $name = (string) ($toolCall['function']['name'] ?? '');
                $arguments = \json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                $arguments = \is_array($arguments) ? $arguments : [];

                if ('propose_edits' === $name && null !== $validateOps) {
                    $ops = \is_array($arguments['ops'] ?? null) ? $arguments['ops'] : [];
                    $summary = (string) ($arguments['summary'] ?? '');
                    $errors = $validateOps($ops);

                    if ([] === $errors) {
                        $reply = \trim((string) ($message['content'] ?? ''));

                        return [
                            'reply' => '' !== $reply ? $reply : $summary,
                            'actions' => [['type' => 'proposeEdits', 'summary' => $summary, 'ops' => $ops]],
                        ];
                    }

                    if ($proposalRetried) {
                        return [
                            'reply' => 'I could not produce a valid change for this request. Please try rephrasing it.',
                            'actions' => [],
                        ];
                    }

                    $proposalRetried = true;
                    $conversation[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                        'content' => (string) \json_encode([
                            'errors' => $errors,
                            'instruction' => 'The proposed operations are invalid. Fix them and call propose_edits again.',
                        ]),
                    ];

                    continue;
                }

                if ('propose_navigation' === $name) {
                    $rawTargets = \is_array($arguments['targets'] ?? null) ? $arguments['targets'] : [];
                    $navigationMessage = (string) ($arguments['message'] ?? '');

                    $targets = [];
                    $errors = [];
                    foreach ($rawTargets as $index => $rawTarget) {
                        $type = (string) ($rawTarget['type'] ?? '');
                        $id = (string) ($rawTarget['id'] ?? '');
                        $targetLocale = (string) ($rawTarget['locale'] ?? '');
                        $known = $this->targetCollector->get($type, $id, $targetLocale);
                        if (null === $known) {
                            $errors[] = \sprintf('target %d: %s:%s:%s was not returned by search_content.', $index, $type, $id, $targetLocale);

                            continue;
                        }
                        $targets[] = $known;
                    }

                    if ([] === $rawTargets) {
                        $errors[] = 'targets must not be empty.';
                    }

                    if ([] === $errors) {
                        $reply = \trim((string) ($message['content'] ?? ''));

                        return [
                            'reply' => '' !== $reply ? $reply : $navigationMessage,
                            'actions' => [['type' => 'navigate', 'message' => $navigationMessage, 'targets' => $targets]],
                        ];
                    }

                    if ($navigationRetried) {
                        return [
                            'reply' => 'I could not resolve the content you asked about. Please try rephrasing your request.',
                            'actions' => [],
                        ];
                    }

                    $navigationRetried = true;
                    $conversation[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                        'content' => (string) \json_encode([
                            'errors' => $errors,
                            'instruction' => 'Only propose targets exactly as returned by search_content in this conversation turn. Call search_content first if needed.',
                        ]),
                    ];

                    continue;
                }

                $tool = $this->toolRegistry->get($name);
                $result = null !== $tool
                    ? $tool->execute($arguments)
                    : ['error' => \sprintf('Unknown tool "%s".', $name)];
                $conversation[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                    'content' => (string) \json_encode($result),
                ];
            }
        }

        return [
            'reply' => 'I could not finish processing this request. Please try again.',
            'actions' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $conversation
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed> the assistant message from the first choice
     */
    private function requestCompletion(string $apiUrl, string $apiKey, string $model, array $conversation, array $tools): array
    {
        $data = $this->client->postJson($apiUrl, $apiKey, '/chat/completions', [
            'model' => $model,
            'messages' => $conversation,
            'tools' => $tools,
        ]);

        $message = $data['choices'][0]['message'] ?? null;
        if (!\is_array($message)) {
            throw new \RuntimeException('AI reply did not contain a message.');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function proposeNavigationDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'propose_navigation',
                'description' => 'Offer the user to open one or more content items found via search_content. The user has to confirm with a click - nothing opens automatically. Pass type, id and locale exactly as returned by search_content.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => [
                            'type' => 'string',
                            'description' => 'One sentence describing what will be opened, in the user\'s language.',
                        ],
                        'targets' => [
                            'type' => 'array',
                            'description' => 'The content items the user may open.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['type' => 'string'],
                                    'id' => ['type' => 'string'],
                                    'locale' => ['type' => 'string'],
                                ],
                                'required' => ['type', 'id', 'locale'],
                            ],
                        ],
                    ],
                    'required' => ['message', 'targets'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposeEditsDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'propose_edits',
                'description' => 'Propose changes to the page. The user will review a diff and approve or reject the changes. Use the operation formats documented in the system prompt.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => [
                            'type' => 'string',
                            'description' => 'One sentence describing the proposed changes, in the user\'s language.',
                        ],
                        'ops' => [
                            'type' => 'array',
                            'description' => 'The list of edit operations.',
                            'items' => ['type' => 'object'],
                        ],
                    ],
                    'required' => ['summary', 'ops'],
                ],
            ],
        ];
    }
}
