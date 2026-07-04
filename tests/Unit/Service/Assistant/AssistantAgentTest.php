<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\AssistantAgent;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\ToolRegistry;
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

    private function agent(MockHttpClient $client, ?AssistantToolInterface $tool = null): AssistantAgent
    {
        return new AssistantAgent($client, new ToolRegistry($tool ? [$tool] : []));
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

    public function testApiErrorSurfacesAsRuntimeException(): void
    {
        $body = (string) \json_encode(['error' => ['message' => 'model overloaded']]);
        $client = new MockHttpClient([new MockResponse($body, ['http_code' => 500])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model overloaded');

        $this->runAgent($this->agent($client));
    }
}
