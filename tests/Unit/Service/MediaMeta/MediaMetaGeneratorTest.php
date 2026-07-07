<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\MediaMeta;

use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaGenerator;
use Marcostastny\SuluAIBundle\Service\MediaMeta\PreviewNotSupportedException;
use Marcostastny\SuluAIBundle\Service\OpenAiClient;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\ImageConverterInterface;

class MediaMetaGeneratorTest extends TestCase
{
    private function fileVersion(): FileVersion
    {
        $fileVersion = new FileVersion();
        $fileVersion->setName('pool.jpg');
        $fileVersion->setVersion(1);
        $fileVersion->setMimeType('image/jpeg');

        return $fileVersion;
    }

    /**
     * @param array<string, mixed> $apiResponse
     */
    private function generator(array $apiResponse, ?array &$capturedJson = null): MediaMetaGenerator
    {
        $converter = $this->createMock(ImageConverterInterface::class);
        $converter->method('convert')->willReturn('BINARY');

        $client = $this->createMock(OpenAiClient::class);
        $client->method('postJson')->willReturnCallback(
            static function (string $apiUrl, string $apiKey, string $path, array $json) use ($apiResponse, &$capturedJson): array {
                $capturedJson = $json;

                return $apiResponse;
            }
        );

        return new MediaMetaGenerator($client, $converter);
    }

    /**
     * @param array<string, array{title: string, description: string}> $meta
     *
     * @return array<string, mixed>
     */
    private function reply(array $meta): array
    {
        return ['choices' => [['message' => ['content' => \json_encode($meta)]]]];
    }

    public function testGeneratesTitleAndDescriptionPerLocale(): void
    {
        $generator = $this->generator($this->reply([
            'de' => ['title' => 'Pool', 'description' => 'Aussenpool des Hotels bei Sonnenschein.'],
            'en' => ['title' => 'Pool', 'description' => 'Outdoor hotel pool in sunshine.'],
        ]), $captured);

        $result = $generator->generate('https://api.test/v1', 'key', 'gpt-test', $this->fileVersion(), ['de', 'en']);

        $this->assertSame('Outdoor hotel pool in sunshine.', $result['en']['description']);
        $this->assertSame('Pool', $result['de']['title']);
        // Vision request shape: json_object + data-URI image part.
        $this->assertSame(['type' => 'json_object'], $captured['response_format']);
        $userContent = $captured['messages'][1]['content'];
        $this->assertSame('image_url', $userContent[1]['type']);
        $this->assertSame('data:image/jpeg;base64,' . \base64_encode('BINARY'), $userContent[1]['image_url']['url']);
        // Both locales are demanded from the model.
        $this->assertStringContainsString('"de"', $captured['messages'][0]['content']);
        $this->assertStringContainsString('"en"', $captured['messages'][0]['content']);
    }

    public function testExistingMetaIsIncludedInThePrompt(): void
    {
        $generator = $this->generator($this->reply([
            'de' => ['title' => 'T', 'description' => 'D'],
        ]), $captured);

        $generator->generate('https://api.test/v1', 'key', 'gpt-test', $this->fileVersion(), ['de'], [
            'de' => ['title' => 'Alter Titel', 'description' => ''],
        ]);

        $this->assertStringContainsString('Alter Titel', $captured['messages'][1]['content'][0]['text']);
    }

    public function testMissingLocaleInReplyThrows(): void
    {
        $generator = $this->generator($this->reply([
            'de' => ['title' => 'Pool', 'description' => 'Beschreibung.'],
        ]));

        $this->expectException(\RuntimeException::class);

        $generator->generate('https://api.test/v1', 'key', 'gpt-test', $this->fileVersion(), ['de', 'en']);
    }

    public function testEmptyFieldInReplyThrows(): void
    {
        $generator = $this->generator($this->reply([
            'de' => ['title' => 'Pool', 'description' => ''],
        ]));

        $this->expectException(\RuntimeException::class);

        $generator->generate('https://api.test/v1', 'key', 'gpt-test', $this->fileVersion(), ['de']);
    }

    public function testCodeFencedReplyIsParsed(): void
    {
        $meta = ['de' => ['title' => 'Pool', 'description' => 'Beschreibung.']];
        $generator = $this->generator([
            'choices' => [['message' => ['content' => "```json\n" . \json_encode($meta) . "\n```"]]],
        ]);

        $result = $generator->generate('https://api.test/v1', 'key', 'gpt-test', $this->fileVersion(), ['de']);

        $this->assertSame('Pool', $result['de']['title']);
    }

    public function testConvertFailureThrowsPreviewNotSupported(): void
    {
        $converter = $this->createMock(ImageConverterInterface::class);
        $converter->method('convert')->willThrowException(new \RuntimeException('no strategy'));
        $client = $this->createMock(OpenAiClient::class);
        $client->expects($this->never())->method('postJson');

        $this->expectException(PreviewNotSupportedException::class);

        (new MediaMetaGenerator($client, $converter))
            ->generate('https://api.test/v1', 'key', 'gpt-test', $this->fileVersion(), ['de']);
    }
}
