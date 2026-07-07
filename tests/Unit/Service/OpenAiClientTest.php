<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service;

use Marcostastny\SuluAIBundle\Service\OpenAiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiClientTest extends TestCase
{
    public function testStreamedContentDeltasAreForwardedAndAssembled(): void
    {
        $body = [
            "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\",\"content\":\"Hal\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"cont", // frame split mid-line
            "ent\":\"lo\"}}]}\n\ndata: [DONE]\n\n",
        ];
        $client = new OpenAiClient(new MockHttpClient([new MockResponse($body)]));

        $fragments = [];
        $data = $client->postJsonStreamed('https://api.test/v1', 'key', '/chat/completions', [
            'model' => 'gpt-test',
            'messages' => [],
        ], function (string $fragment) use (&$fragments): void {
            $fragments[] = $fragment;
        });

        $this->assertSame(['Hal', 'lo'], $fragments);
        $this->assertSame('Hallo', $data['choices'][0]['message']['content']);
        $this->assertArrayNotHasKey('tool_calls', $data['choices'][0]['message']);
    }

    public function testStreamedToolCallDeltasAssembleByIndex(): void
    {
        $first = [
            'choices' => [['delta' => ['role' => 'assistant', 'tool_calls' => [[
                'index' => 0,
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'run_select_query', 'arguments' => '{"sql":'],
            ]]]]],
        ];
        $second = [
            'choices' => [['delta' => ['tool_calls' => [[
                'index' => 0,
                'function' => ['arguments' => '"SELECT 1"}'],
            ]]]]],
        ];
        $body = 'data: ' . \json_encode($first) . "\n\n"
            . 'data: ' . \json_encode($second) . "\n\n"
            . "data: [DONE]\n\n";
        $client = new OpenAiClient(new MockHttpClient([new MockResponse($body)]));

        $data = $client->postJsonStreamed('https://api.test/v1', 'key', '/chat/completions', [
            'model' => 'gpt-test',
            'messages' => [],
        ], static function (): void {});

        $message = $data['choices'][0]['message'];
        $this->assertNull($message['content']);
        $this->assertSame('call_1', $message['tool_calls'][0]['id']);
        $this->assertSame('run_select_query', $message['tool_calls'][0]['function']['name']);
        $this->assertSame('{"sql":"SELECT 1"}', $message['tool_calls'][0]['function']['arguments']);
    }

    public function testStreamedRequestSetsStreamFlag(): void
    {
        $client = new OpenAiClient(new MockHttpClient(function ($method, $url, $options) {
            $this->assertStringContainsString('"stream":true', $options['body']);

            return new MockResponse("data: {\"choices\":[{\"delta\":{\"content\":\"x\"}}]}\n\ndata: [DONE]\n\n");
        }));

        $client->postJsonStreamed('https://api.test/v1', 'key', '/chat/completions', [
            'model' => 'gpt-test',
            'messages' => [],
        ], static function (): void {});
    }

    public function testStreamedErrorResponseThrows(): void
    {
        $response = new MockResponse((string) \json_encode([
            'error' => ['message' => 'invalid key'],
        ]), ['http_code' => 401]);
        $client = new OpenAiClient(new MockHttpClient([$response]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid key');

        $client->postJsonStreamed('https://api.test/v1', 'bad', '/chat/completions', [
            'model' => 'gpt-test',
            'messages' => [],
        ], static function (): void {});
    }
}
