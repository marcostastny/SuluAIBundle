<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs the OpenAI-compatible function-calling loop for the page assistant.
 * Server tools from the ToolRegistry are executed in-loop; the propose_edits
 * tool is terminal and returned to the client as an action.
 */
class AssistantAgent
{
    private const MAX_ITERATIONS = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ToolRegistry $toolRegistry,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param callable(array<int, mixed>): list<string> $validateOps returns error strings, empty when valid
     *
     * @return array{reply: string, actions: list<array{type: string, summary: string, ops: array<int, mixed>}>}
     */
    public function run(
        string $apiUrl,
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        callable $validateOps
    ): array {
        $conversation = [['role' => 'system', 'content' => $systemPrompt], ...$messages];
        $tools = [$this->proposeEditsDefinition(), ...$this->toolRegistry->getDefinitions()];
        $proposalRetried = false;

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

                if ('propose_edits' === $name) {
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
        $response = $this->httpClient->request(
            'POST',
            \rtrim($apiUrl, '/') . '/chat/completions',
            [
                'auth_bearer' => $apiKey,
                'json' => [
                    'model' => $model,
                    'messages' => $conversation,
                    'tools' => $tools,
                ],
            ]
        );

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode >= 400) {
            $message = $data['error']['message'] ?? \json_encode($data);

            throw new \RuntimeException(\sprintf('API returned status %d: %s', $statusCode, $message));
        }

        $message = $data['choices'][0]['message'] ?? null;
        if (!\is_array($message)) {
            throw new \RuntimeException('AI reply did not contain a message.');
        }

        return $message;
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
