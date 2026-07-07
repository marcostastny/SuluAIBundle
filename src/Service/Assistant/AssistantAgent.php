<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\OpenAiClient;

/**
 * Runs the OpenAI-compatible function-calling loop for the assistant.
 * Server tools from the ToolRegistry are executed in-loop; propose_edits,
 * propose_navigation, switch_tab and propose_page_creation are terminal and
 * returned to the client as actions.
 */
class AssistantAgent
{
    // Data-query turns legitimately need several round-trips (list tables,
    // explore, final query, reply), so allow more than the old cap of 5.
    private const MAX_ITERATIONS = 8;

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
     * @param array{current: string, available: list<string>}|null $tabs edit-form tabs; enables switch_tab
     *                                                                   when another tab is available
     * @param (callable(array<string, mixed>): array)|null $validateCreation returns ['action' => …] or
     *                                                                       ['errors' => …]; null disables
     *                                                                       propose_page_creation
     * @param (callable(array<string, mixed>): void)|null $onEvent receives progress events
     *                                                             (delta/status/reset) while the loop
     *                                                             runs; also switches the completion
     *                                                             requests to streaming mode
     *
     * @return array{reply: string, actions: list<array<string, mixed>>}
     */
    public function run(
        string $apiUrl,
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        ?callable $validateOps,
        ?array $tabs = null,
        ?callable $validateCreation = null,
        ?callable $onEvent = null
    ): array {
        $this->targetCollector->reset();

        $conversation = [['role' => 'system', 'content' => $systemPrompt], ...$messages];
        $tools = [$this->proposeNavigationDefinition(), ...$this->toolRegistry->getDefinitions()];
        if (null !== $validateOps) {
            $tools = [$this->proposeEditsDefinition(), ...$tools];
        }
        if (null !== $validateCreation) {
            $tools[] = $this->proposePageCreationDefinition();
        }
        $switchableTabs = null !== $tabs
            ? \array_values(\array_diff($tabs['available'], [$tabs['current']]))
            : [];
        if ([] !== $switchableTabs) {
            $tools[] = $this->switchTabDefinition($switchableTabs);
        }
        $proposalRetried = false;
        $navigationRetried = false;
        $switchTabRetried = false;
        $creationRetried = false;

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; ++$iteration) {
            $message = $this->requestCompletion($apiUrl, $apiKey, $model, $conversation, $tools, $onEvent);
            $toolCalls = $message['tool_calls'] ?? [];

            if (!\is_array($toolCalls) || [] === $toolCalls) {
                return ['reply' => \trim((string) ($message['content'] ?? '')), 'actions' => []];
            }

            // Content streamed in this iteration is interim thinking when the
            // loop continues; the client clears it on the reset event.
            $streamedText = null !== $onEvent && '' !== \trim((string) ($message['content'] ?? ''));

            $conversation[] = $message;

            foreach ($toolCalls as $toolCall) {
                if (!\is_array($toolCall) || !\is_array($toolCall['function'] ?? null)) {
                    continue;
                }
                $name = (string) ($toolCall['function']['name'] ?? '');
                if (null !== $onEvent) {
                    $onEvent(['type' => 'status', 'tool' => $name]);
                }
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
                            'actions' => [[
                                'type' => 'proposeEdits',
                                'summary' => $summary,
                                'ops' => $ops,
                                'resume' => (bool) ($arguments['resume'] ?? false),
                            ]],
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
                            'actions' => [[
                                'type' => 'navigate',
                                'message' => $navigationMessage,
                                'targets' => $targets,
                                'resume' => (bool) ($arguments['resume'] ?? false),
                            ]],
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

                if ('switch_tab' === $name && [] !== $switchableTabs) {
                    $tab = (string) ($arguments['tab'] ?? '');
                    $switchMessage = (string) ($arguments['message'] ?? '');

                    if (\in_array($tab, $switchableTabs, true)) {
                        $reply = \trim((string) ($message['content'] ?? ''));

                        return [
                            'reply' => '' !== $reply ? $reply : $switchMessage,
                            'actions' => [[
                                'type' => 'switchTab',
                                'tab' => $tab,
                                'message' => $switchMessage,
                                'resume' => (bool) ($arguments['resume'] ?? false),
                            ]],
                        ];
                    }

                    if ($switchTabRetried) {
                        return [
                            'reply' => 'I could not switch to the requested tab. Please switch manually.',
                            'actions' => [],
                        ];
                    }

                    $switchTabRetried = true;
                    $conversation[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                        'content' => (string) \json_encode([
                            'errors' => [\sprintf('tab "%s" is not available; available tabs: %s.', $tab, \implode(', ', $switchableTabs))],
                            'instruction' => 'Call switch_tab with one of the available tabs, or answer in text.',
                        ]),
                    ];

                    continue;
                }

                if ('propose_page_creation' === $name && null !== $validateCreation) {
                    $result = $validateCreation($arguments);

                    if (isset($result['action'])) {
                        $reply = \trim((string) ($message['content'] ?? ''));

                        return [
                            'reply' => '' !== $reply ? $reply : (string) ($arguments['message'] ?? ''),
                            'actions' => [$result['action']],
                        ];
                    }

                    if ($creationRetried) {
                        return [
                            'reply' => 'I could not prepare the page creation. Please try rephrasing your request.',
                            'actions' => [],
                        ];
                    }

                    $creationRetried = true;
                    $conversation[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                        'content' => (string) \json_encode([
                            'errors' => $result['errors'] ?? [],
                            'instruction' => 'Fix the arguments and call propose_page_creation again. Use search_content to find a valid parent page when needed.',
                        ]),
                    ];

                    continue;
                }

                $tool = $this->toolRegistry->get($name);
                if (null === $tool) {
                    $result = ['error' => \sprintf('Unknown tool "%s".', $name)];
                } else {
                    try {
                        $result = $tool->execute($arguments);
                    } catch (\Throwable $e) {
                        // Report the failure to the model so the turn can recover
                        // instead of aborting the whole request.
                        $result = ['error' => \sprintf('Tool "%s" failed: %s', $name, $e->getMessage())];
                    }
                }
                $conversation[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                    'content' => (string) \json_encode($result),
                ];
            }

            if ($streamedText && null !== $onEvent) {
                $onEvent(['type' => 'reset']);
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
    private function requestCompletion(string $apiUrl, string $apiKey, string $model, array $conversation, array $tools, ?callable $onEvent = null): array
    {
        $payload = ['model' => $model, 'messages' => $conversation, 'tools' => $tools];
        $data = null !== $onEvent
            ? $this->client->postJsonStreamed($apiUrl, $apiKey, '/chat/completions', $payload, static function (string $fragment) use ($onEvent): void {
                $onEvent(['type' => 'delta', 'text' => $fragment]);
            })
            : $this->client->postJson($apiUrl, $apiKey, '/chat/completions', $payload);

        $message = $data['choices'][0]['message'] ?? null;
        if (!\is_array($message)) {
            throw new \RuntimeException('AI reply did not contain a message.');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumeParameter(): array
    {
        return [
            'type' => 'boolean',
            'description' => 'Set to true when the overall task is NOT finished after this action. After the user approves it you are automatically called again with the updated context so you can continue. Omit or set false when this action completes the task.',
        ];
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
                        'resume' => $this->resumeParameter(),
                    ],
                    'required' => ['message', 'targets'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposePageCreationDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'propose_page_creation',
                'description' => 'Propose creating a new page as a draft. The user reviews title, template, parent and URL and creates the page with one click - nothing is created automatically. The parent must be "homepage" or a pages result from search_content in this conversation turn.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => [
                            'type' => 'string',
                            'description' => 'One sentence describing the page to create, in the user\'s language.',
                        ],
                        'title' => ['type' => 'string', 'description' => 'The page title.'],
                        'template' => ['type' => 'string', 'description' => 'The page template key, e.g. "default".'],
                        'parent_id' => [
                            'type' => 'string',
                            'description' => '"homepage" for a top-level page, or the id of a pages result from search_content.',
                        ],
                        'parent_locale' => [
                            'type' => 'string',
                            'description' => 'The locale of the parent exactly as returned by search_content. Omit when parent_id is "homepage".',
                        ],
                        'locale' => ['type' => 'string', 'description' => 'The locale to create the page in, e.g. "de".'],
                        'resume' => $this->resumeParameter(),
                    ],
                    'required' => ['message', 'title', 'template', 'parent_id', 'locale'],
                ],
            ],
        ];
    }

    /**
     * @param non-empty-list<string> $tabs
     *
     * @return array<string, mixed>
     */
    private function switchTabDefinition(array $tabs): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'switch_tab',
                'description' => 'Ask the user to switch to another tab of the currently open edit form. The user has to confirm with a click; unsaved changes are saved first. Only fields of the active tab are editable, so switch before proposing edits to fields that live on another tab.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tab' => [
                            'type' => 'string',
                            'enum' => $tabs,
                            'description' => 'The tab to switch to.',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'One sentence describing why the switch is needed, in the user\'s language.',
                        ],
                        'resume' => $this->resumeParameter(),
                    ],
                    'required' => ['tab', 'message'],
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
                        'resume' => $this->resumeParameter(),
                    ],
                    'required' => ['summary', 'ops'],
                ],
            ],
        ];
    }
}
