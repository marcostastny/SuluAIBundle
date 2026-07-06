<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\AssistantAgent;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\ToolRegistry;
use Marcostastny\SuluAIBundle\Service\OpenAiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AssistantAgentTest extends TestCase
{
    private function textResponse(string $content): MockResponse
    {
        return new MockResponse((string) \json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ]));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toolCallResponse(string $name, array $arguments, string $id = 'call_1'): MockResponse
    {
        return new MockResponse((string) \json_encode([
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $id,
                    'type' => 'function',
                    'function' => ['name' => $name, 'arguments' => (string) \json_encode($arguments)],
                ]],
            ]]],
        ]));
    }

    private NavigationTargetCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new NavigationTargetCollector();
    }

    private function agent(MockHttpClient $client, ?AssistantToolInterface $tool = null): AssistantAgent
    {
        return new AssistantAgent(new OpenAiClient($client), new ToolRegistry($tool ? [$tool] : []), $this->collector);
    }

    private function collectingTool(): AssistantToolInterface
    {
        $collector = $this->collector;

        return new class($collector) implements AssistantToolInterface {
            public function __construct(private NavigationTargetCollector $collector)
            {
            }

            public function getName(): string
            {
                return 'search_content';
            }

            public function getDefinition(): array
            {
                return ['type' => 'function', 'function' => ['name' => 'search_content', 'parameters' => ['type' => 'object', 'properties' => []]]];
            }

            public function execute(array $arguments): array
            {
                $this->collector->add([
                    'type' => 'pages',
                    'id' => '42',
                    'locale' => 'de',
                    'title' => 'Zimmer',
                    'view' => 'sulu_page.page_edit_form',
                    'attributes' => ['id' => '42', 'locale' => 'de', 'webspace' => 'kulm'],
                ]);

                return ['results' => [['type' => 'pages', 'id' => '42', 'locale' => 'de', 'title' => 'Zimmer']]];
            }
        };
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array{reply: string, actions: list<array<string, mixed>>}
     */
    private function runAgent(AssistantAgent $agent, array $messages = [['role' => 'user', 'content' => 'hi']]): array
    {
        return $agent->run('https://api.test/v1', 'key', 'gpt-test', 'system prompt', $messages, static fn (array $ops) => []);
    }

    public function testPlainTextReply(): void
    {
        $client = new MockHttpClient([$this->textResponse('The page is about a hotel.')]);

        $result = $this->runAgent($this->agent($client));

        $this->assertSame('The page is about a hotel.', $result['reply']);
        $this->assertSame([], $result['actions']);
    }

    public function testProposeEditsReturnsAction(): void
    {
        $ops = [['op' => 'set', 'path' => '/title', 'value' => 'New']];
        $client = new MockHttpClient([
            $this->toolCallResponse('propose_edits', ['summary' => 'Change the title', 'ops' => $ops]),
        ]);

        $result = $this->runAgent($this->agent($client));

        $this->assertSame('Change the title', $result['reply']);
        $this->assertCount(1, $result['actions']);
        $this->assertSame('proposeEdits', $result['actions'][0]['type']);
        $this->assertSame($ops, $result['actions'][0]['ops']);
    }

    public function testInvalidOpsGetOneCorrectiveRound(): void
    {
        $badOps = [['op' => 'set', 'path' => '/bad', 'value' => 'x']];
        $goodOps = [['op' => 'set', 'path' => '/title', 'value' => 'x']];
        $client = new MockHttpClient([
            $this->toolCallResponse('propose_edits', ['summary' => 'try 1', 'ops' => $badOps]),
            $this->toolCallResponse('propose_edits', ['summary' => 'try 2', 'ops' => $goodOps], 'call_2'),
        ]);
        $agent = $this->agent($client);

        $result = $agent->run(
            'https://api.test/v1',
            'key',
            'gpt-test',
            'system',
            [['role' => 'user', 'content' => 'edit']],
            static fn (array $ops) => $ops === $badOps ? ['op 0: unknown property "bad".'] : []
        );

        $this->assertSame(2, $client->getRequestsCount());
        $this->assertCount(1, $result['actions']);
        $this->assertSame($goodOps, $result['actions'][0]['ops']);
    }

    public function testTwiceInvalidOpsReturnApologyWithoutActions(): void
    {
        $badOps = [['op' => 'set', 'path' => '/bad', 'value' => 'x']];
        $client = new MockHttpClient([
            $this->toolCallResponse('propose_edits', ['summary' => 'try 1', 'ops' => $badOps]),
            $this->toolCallResponse('propose_edits', ['summary' => 'try 2', 'ops' => $badOps], 'call_2'),
        ]);
        $agent = $this->agent($client);

        $result = $agent->run(
            'https://api.test/v1',
            'key',
            'gpt-test',
            'system',
            [['role' => 'user', 'content' => 'edit']],
            static fn (array $ops) => ['op 0: unknown property "bad".']
        );

        $this->assertSame([], $result['actions']);
        $this->assertNotSame('', $result['reply']);
    }

    public function testServerToolIsExecutedAndLoopContinues(): void
    {
        $tool = new class() implements AssistantToolInterface {
            public array $receivedArguments = [];

            public function getName(): string
            {
                return 'lookup';
            }

            public function getDefinition(): array
            {
                return ['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object', 'properties' => []]]];
            }

            public function execute(array $arguments): array
            {
                $this->receivedArguments = $arguments;

                return ['found' => true];
            }
        };
        $client = new MockHttpClient([
            $this->toolCallResponse('lookup', ['query' => 'rooms']),
            $this->textResponse('Found it.'),
        ]);

        $result = $this->runAgent($this->agent($client, $tool));

        $this->assertSame(['query' => 'rooms'], $tool->receivedArguments);
        $this->assertSame('Found it.', $result['reply']);
        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testIterationCapReturnsFallbackReply(): void
    {
        $responses = [];
        for ($i = 0; $i < 6; ++$i) {
            $responses[] = $this->toolCallResponse('lookup', [], 'call_' . $i);
        }
        $tool = new class() implements AssistantToolInterface {
            public function getName(): string
            {
                return 'lookup';
            }

            public function getDefinition(): array
            {
                return ['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object', 'properties' => []]]];
            }

            public function execute(array $arguments): array
            {
                return [];
            }
        };
        $client = new MockHttpClient($responses);

        $result = $this->runAgent($this->agent($client, $tool));

        $this->assertSame([], $result['actions']);
        $this->assertNotSame('', $result['reply']);
        $this->assertSame(5, $client->getRequestsCount());
    }

    public function testProposeNavigationReturnsCollectedTargets(): void
    {
        $client = new MockHttpClient([
            $this->toolCallResponse('search_content', ['query' => 'zimmer']),
            $this->toolCallResponse('propose_navigation', [
                'message' => 'Open the Zimmer page',
                'targets' => [['type' => 'pages', 'id' => '42', 'locale' => 'de']],
            ], 'call_2'),
        ]);

        $result = $this->runAgent($this->agent($client, $this->collectingTool()));

        $this->assertSame('Open the Zimmer page', $result['reply']);
        $this->assertCount(1, $result['actions']);
        $this->assertSame('navigate', $result['actions'][0]['type']);
        $this->assertSame('sulu_page.page_edit_form', $result['actions'][0]['targets'][0]['view']);
        $this->assertSame(
            ['id' => '42', 'locale' => 'de', 'webspace' => 'kulm'],
            $result['actions'][0]['targets'][0]['attributes']
        );
    }

    public function testProposeNavigationWithUnknownTargetGetsOneCorrectiveRound(): void
    {
        $client = new MockHttpClient([
            $this->toolCallResponse('propose_navigation', [
                'message' => 'Open it',
                'targets' => [['type' => 'pages', 'id' => '99', 'locale' => 'de']],
            ]),
            $this->toolCallResponse('propose_navigation', [
                'message' => 'Open it',
                'targets' => [['type' => 'pages', 'id' => '99', 'locale' => 'de']],
            ], 'call_2'),
        ]);

        $result = $this->runAgent($this->agent($client));

        $this->assertSame(2, $client->getRequestsCount());
        $this->assertSame([], $result['actions']);
        $this->assertNotSame('', $result['reply']);
    }

    public function testRunResetsCollectorFromPreviousRequest(): void
    {
        $this->collector->add([
            'type' => 'pages',
            'id' => '1',
            'locale' => 'de',
            'title' => 'Stale',
            'view' => 'sulu_page.page_edit_form',
            'attributes' => [],
        ]);
        $client = new MockHttpClient([
            $this->toolCallResponse('propose_navigation', [
                'message' => 'Open it',
                'targets' => [['type' => 'pages', 'id' => '1', 'locale' => 'de']],
            ]),
            $this->textResponse('Sorry.'),
        ]);

        $result = $this->runAgent($this->agent($client));

        $this->assertSame([], $result['actions']);
    }

    public function testToolListOmitsProposeEditsWithoutValidator(): void
    {
        $captured = null;
        $client = new MockHttpClient(function ($method, $url, $options) use (&$captured) {
            $captured = \json_decode($options['body'], true);

            return $this->textResponse('ok');
        });

        $this->agent($client)->run('https://api.test/v1', 'key', 'gpt-test', 'system', [['role' => 'user', 'content' => 'hi']], null);

        $names = \array_map(static fn (array $tool): string => $tool['function']['name'], $captured['tools']);
        $this->assertNotContains('propose_edits', $names);
        $this->assertContains('propose_navigation', $names);
    }

    public function testToolListContainsProposeEditsWithValidator(): void
    {
        $captured = null;
        $client = new MockHttpClient(function ($method, $url, $options) use (&$captured) {
            $captured = \json_decode($options['body'], true);

            return $this->textResponse('ok');
        });

        $this->runAgent($this->agent($client));

        $names = \array_map(static fn (array $tool): string => $tool['function']['name'], $captured['tools']);
        $this->assertContains('propose_edits', $names);
        $this->assertContains('propose_navigation', $names);
    }

    public function testProposeEditsCarriesResumeFlag(): void
    {
        $client = new MockHttpClient([
            $this->toolCallResponse('propose_edits', [
                'summary' => 'Preise erhöht.',
                'ops' => [['op' => 'set', 'path' => '/title', 'value' => 'x']],
                'resume' => true,
            ]),
        ]);

        $result = $this->runAgent($this->agent($client));

        $this->assertTrue($result['actions'][0]['resume']);
    }

    public function testProposeEditsDefaultsResumeToFalse(): void
    {
        $client = new MockHttpClient([
            $this->toolCallResponse('propose_edits', [
                'summary' => 'Titel geändert.',
                'ops' => [['op' => 'set', 'path' => '/title', 'value' => 'x']],
            ]),
        ]);

        $result = $this->runAgent($this->agent($client));

        $this->assertFalse($result['actions'][0]['resume']);
    }

    public function testProposeNavigationCarriesResumeFlag(): void
    {
        $client = new MockHttpClient([
            $this->toolCallResponse('search_content', ['query' => 'Zimmer']),
            $this->toolCallResponse('propose_navigation', [
                'message' => 'Zimmerseite öffnen?',
                'targets' => [['type' => 'pages', 'id' => '42', 'locale' => 'de']],
                'resume' => true,
            ], 'call_2'),
        ]);

        $result = $this->runAgent($this->agent($client, $this->collectingTool()));

        $this->assertSame('navigate', $result['actions'][0]['type']);
        $this->assertTrue($result['actions'][0]['resume']);
    }

    public function testSwitchTabReturnsTerminalAction(): void
    {
        $client = new MockHttpClient([
            $this->toolCallResponse('switch_tab', ['tab' => 'seo', 'message' => 'Zum SEO-Tab wechseln.', 'resume' => true]),
        ]);

        $result = $this->agent($client)->run(
            'https://api.test/v1',
            'key',
            'gpt-test',
            'system prompt',
            [['role' => 'user', 'content' => 'bitte SEO-Texte anpassen']],
            static fn (array $ops) => [],
            ['current' => 'content', 'available' => ['content', 'seo']]
        );

        $this->assertSame('Zum SEO-Tab wechseln.', $result['reply']);
        $this->assertSame(
            [['type' => 'switchTab', 'tab' => 'seo', 'message' => 'Zum SEO-Tab wechseln.', 'resume' => true]],
            $result['actions']
        );
    }

    public function testSwitchTabRejectsUnknownTabThenRecovers(): void
    {
        $client = new MockHttpClient([
            $this->toolCallResponse('switch_tab', ['tab' => 'excerpt', 'message' => 'Wechsel.']),
            $this->textResponse('Das geht hier nicht.'),
        ]);

        $result = $this->agent($client)->run(
            'https://api.test/v1',
            'key',
            'gpt-test',
            'system prompt',
            [['role' => 'user', 'content' => 'hi']],
            static fn (array $ops) => [],
            ['current' => 'content', 'available' => ['content', 'seo']]
        );

        $this->assertSame('Das geht hier nicht.', $result['reply']);
        $this->assertSame([], $result['actions']);
    }

    public function testSwitchTabWithoutTabContextFallsThroughToUnknownTool(): void
    {
        // No $tabs passed: switch_tab is not registered, so a hallucinated call
        // must produce a tool-error message and let the model recover.
        $client = new MockHttpClient([
            $this->toolCallResponse('switch_tab', ['tab' => 'seo', 'message' => 'Wechsel.']),
            $this->textResponse('Ok, anders.'),
        ]);

        $result = $this->runAgent($this->agent($client));

        $this->assertSame('Ok, anders.', $result['reply']);
        $this->assertSame([], $result['actions']);
    }

    public function testApiErrorSurfacesAsRuntimeException(): void
    {
        $body = (string) \json_encode(['error' => ['message' => 'model overloaded']]);
        $client = new MockHttpClient([new MockResponse($body, ['http_code' => 500])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model overloaded');

        $this->runAgent($this->agent($client));
    }
}
