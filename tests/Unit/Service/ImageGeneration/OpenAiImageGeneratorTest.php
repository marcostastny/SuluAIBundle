<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\ImageGeneration;

use Marcostastny\SuluAIBundle\Service\ImageGeneration\OpenAiImageGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OpenAiImageGeneratorTest extends TestCase
{
    public function testGenerateWithoutReferencesPostsToGenerations(): void
    {
        $capturedUrl = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): ResponseInterface {
            $capturedUrl = $url;

            return new MockResponse((string) json_encode(['data' => [['b64_json' => 'AAA'], ['b64_json' => 'BBB']]]));
        });
        $generator = new OpenAiImageGenerator($client);

        $result = $generator->generate('https://api.test/v1', 'secret', 'gpt-image-2', 'a cat', '1024x1024', 2, []);

        $this->assertSame('https://api.test/v1/images/generations', $capturedUrl);
        $this->assertCount(2, $result);
        $this->assertSame('AAA', $result[0]['b64']);
    }

    public function testGenerateWithReferencesPostsToEdits(): void
    {
        $capturedUrl = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): ResponseInterface {
            $capturedUrl = $url;

            return new MockResponse((string) json_encode(['data' => [['url' => 'https://img/1.png']]]));
        });
        $generator = new OpenAiImageGenerator($client);

        $result = $generator->generate(
            'https://api.test/v1',
            'secret',
            'gpt-image-2',
            'a cat',
            '1024x1024',
            1,
            [['filename' => 'ref.png', 'contentType' => 'image/png', 'data' => 'rawbytes']]
        );

        $this->assertSame('https://api.test/v1/images/edits', $capturedUrl);
        $this->assertSame('https://img/1.png', $result[0]['url']);
    }

    public function testGenerateSurfacesApiError(): void
    {
        $body = json_encode(['error' => ['message' => 'model not found']]);
        $client = new MockHttpClient(new MockResponse((string) $body, ['http_code' => 400]));
        $generator = new OpenAiImageGenerator($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model not found');

        $generator->generate('https://api.test/v1', 'secret', 'bad-model', 'a cat', '1024x1024', 1, []);
    }
}
