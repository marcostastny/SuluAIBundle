<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\ImageGeneration;

use Marcostastny\SuluAIBundle\Service\ImageGeneration\GeneratedImageSaver;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class GeneratedImageSaverTest extends TestCase
{
    private function media(int $id): Media
    {
        $media = $this->createMock(Media::class);
        $media->method('getId')->willReturn($id);
        $media->method('getTitle')->willReturn('AI image');
        $media->method('getThumbnails')->willReturn(['sulu-100x100' => '/thumb.jpg']);
        $media->method('getUrl')->willReturn('/media/1/download');

        return $media;
    }

    public function testSavesBase64Payload(): void
    {
        $capturedData = null;
        $manager = $this->createMock(MediaManagerInterface::class);
        $manager->expects($this->once())
            ->method('save')
            ->with(
                $this->isInstanceOf(UploadedFile::class),
                $this->callback(function (array $data) use (&$capturedData): bool {
                    $capturedData = $data;

                    return true;
                }),
                7
            )
            ->willReturn($this->media(5));

        $saver = new GeneratedImageSaver($manager, new MockHttpClient());
        $result = $saver->save(['b64' => base64_encode('PNGDATA'), 'url' => null], 3, 'A cat on a sofa', 'de', 7);

        $this->assertSame(5, $result['id']);
        $this->assertSame(3, $capturedData['collection']);
        $this->assertSame('de', $capturedData['locale']);
        $this->assertSame('A cat on a sofa', $capturedData['title']);
        $this->assertSame('/thumb.jpg', $result['thumbnailUrl']);
    }

    public function testFetchesUrlPayload(): void
    {
        $manager = $this->createMock(MediaManagerInterface::class);
        $manager->method('save')->willReturn($this->media(6));

        $client = new MockHttpClient(new MockResponse('IMAGEBYTES', ['response_headers' => ['content-type' => 'image/png']]));
        $saver = new GeneratedImageSaver($manager, $client);

        $result = $saver->save(['b64' => null, 'url' => 'https://img/1.png'], 3, 'title', 'de', 7);

        $this->assertSame(6, $result['id']);
    }

    public function testThrowsOnEmptyPayload(): void
    {
        $saver = new GeneratedImageSaver($this->createMock(MediaManagerInterface::class), new MockHttpClient());

        $this->expectException(\RuntimeException::class);
        $saver->save(['b64' => null, 'url' => null], 3, 'title', 'de', 7);
    }
}
