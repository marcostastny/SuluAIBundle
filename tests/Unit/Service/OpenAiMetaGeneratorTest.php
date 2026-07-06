<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service;

use Marcostastny\SuluAIBundle\Service\OpenAiClient;
use Marcostastny\SuluAIBundle\Service\OpenAiMetaGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiMetaGeneratorTest extends TestCase
{
    public function testGenerateParsesChatCompletion(): void
    {
        $payload = [
            'choices' => [
                ['message' => ['content' => '{"title":"T","description":"D","keywords":"a, b"}']],
            ],
        ];
        $client = new MockHttpClient(new MockResponse((string) json_encode($payload)));
        $generator = new OpenAiMetaGenerator(new OpenAiClient($client));

        $result = $generator->generate('https://api.test/v1', 'secret', 'gpt-test', 'Title', 'Body', 'en');

        $this->assertSame('T', $result['title']);
        $this->assertSame('D', $result['description']);
        $this->assertSame('a, b', $result['keywords']);
    }

    public function testGenerateSurfacesApiError(): void
    {
        $body = json_encode(['error' => ['message' => "Unsupported value: 'temperature' does not support 0.4"]]);
        $client = new MockHttpClient(new MockResponse((string) $body, ['http_code' => 400]));
        $generator = new OpenAiMetaGenerator(new OpenAiClient($client));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("temperature' does not support 0.4");

        $generator->generate('https://api.test/v1', 'secret', 'gpt-5-mini', 'Title', 'Body', 'en');
    }

    public function testParseReplyExtractsEmbeddedJson(): void
    {
        $generator = new OpenAiMetaGenerator(new OpenAiClient(new MockHttpClient()));
        $result = $generator->parseReply('Sure: {"title":"X","description":"Y","keywords":"z"} done');
        $this->assertSame('X', $result['title']);
        $this->assertSame('Y', $result['description']);
        $this->assertSame('z', $result['keywords']);
    }

    public function testParseReplyThrowsOnNonJson(): void
    {
        $generator = new OpenAiMetaGenerator(new OpenAiClient(new MockHttpClient()));
        $this->expectException(\RuntimeException::class);
        $generator->parseReply('no json at all');
    }
}
