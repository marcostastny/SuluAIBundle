<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\ImageGeneration;

use Marcostastny\SuluAIBundle\Service\ImageGeneration\OpenAiImageGenerator;
use Marcostastny\SuluAIBundle\Service\OpenAiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OpenAiImageGeneratorTest extends TestCase
{
    public function testGenerateWithoutReferencesPostsToGenerations(): void
    {
        $capturedUrl = null;
        $capturedBody = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody): ResponseInterface {
            $capturedUrl = $url;
            $capturedBody = $options['body'] ?? null;

            return new MockResponse((string) json_encode(['data' => [['b64_json' => 'AAA'], ['b64_json' => 'BBB']]]));
        });
        $generator = new OpenAiImageGenerator(new OpenAiClient($client));

        $result = $generator->generate('https://api.test/v1', 'secret', 'gpt-image-2', 'a cat', '1024x1024', 2, []);

        $this->assertSame('https://api.test/v1/images/generations', $capturedUrl);
        // gpt-image-* models reject response_format; it must not be sent.
        $this->assertIsString($capturedBody);
        $this->assertStringNotContainsString('response_format', $capturedBody);
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
        $generator = new OpenAiImageGenerator(new OpenAiClient($client));

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

    public function testReferencesUseRepeatedImageArrayFieldName(): void
    {
        $capturedBody = '';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): ResponseInterface {
            $body = $options['body'];
            if (\is_string($body)) {
                $capturedBody = $body;
            } elseif (\is_callable($body)) {
                // HttpClient normalises an iterable body into a chunk closure.
                while ('' !== ($chunk = $body(16372))) {
                    $capturedBody .= $chunk;
                }
            } else {
                foreach ($body as $chunk) {
                    $capturedBody .= $chunk;
                }
            }

            return new MockResponse((string) json_encode(['data' => [['b64_json' => 'AAA']]]));
        });
        $generator = new OpenAiImageGenerator(new OpenAiClient($client));

        $generator->generate(
            'https://api.test/v1',
            'secret',
            'gpt-image-2',
            'a cat',
            '1024x1024',
            1,
            [
                ['filename' => 'a.png', 'contentType' => 'image/png', 'data' => 'aaa'],
                ['filename' => 'b.png', 'contentType' => 'image/png', 'data' => 'bbb'],
            ]
        );

        // The images/edits endpoint expects repeated "image[]" parts; Symfony's
        // FormDataPart would otherwise name them "image[][0]" and be rejected.
        $this->assertStringContainsString('name="image[]"', $capturedBody);
        $this->assertStringNotContainsString('image[][0]', $capturedBody);
    }

    public function testGenerateUsesLongTimeoutForSlowImageModels(): void
    {
        $capturedOptions = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): ResponseInterface {
            $capturedOptions = $options;

            return new MockResponse((string) json_encode(['data' => [['b64_json' => 'AAA']]]));
        });
        $generator = new OpenAiImageGenerator(new OpenAiClient($client));

        $generator->generate('https://api.test/v1', 'secret', 'gpt-image-2', 'a cat', '1024x1024', 1, []);

        // gpt-image-* renders take longer than default_socket_timeout (60s);
        // without an explicit idle timeout the request dies with
        // "Idle timeout reached" before the image is returned.
        $this->assertGreaterThanOrEqual(300, $capturedOptions['timeout']);
        $this->assertGreaterThanOrEqual(300, $capturedOptions['max_duration']);
    }

    public function testGenerateSurfacesApiError(): void
    {
        $body = json_encode(['error' => ['message' => 'model not found']]);
        $client = new MockHttpClient(new MockResponse((string) $body, ['http_code' => 400]));
        $generator = new OpenAiImageGenerator(new OpenAiClient($client));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model not found');

        $generator->generate('https://api.test/v1', 'secret', 'bad-model', 'a cat', '1024x1024', 1, []);
    }
}
