<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\MediaMeta;

use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaWriter;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\FileVersionMeta;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;

class MediaMetaWriterTest extends TestCase
{
    private const GENERATED = [
        'de' => ['title' => 'Pool', 'description' => 'Aussenpool.'],
        'en' => ['title' => 'Pool', 'description' => 'Outdoor pool.'],
    ];

    /**
     * @param array<string, array{title: string, description: ?string}> $metas locale => values
     */
    private function media(array $metas = [], string $fileName = 'DSC_1234.jpg'): Media
    {
        $media = new Media();
        $reflection = new \ReflectionProperty(Media::class, 'id');
        $reflection->setValue($media, 42);

        $fileVersion = new FileVersion();
        $fileVersion->setName($fileName);
        $fileVersion->setVersion(1);
        $fileVersion->setMimeType('image/jpeg');

        foreach ($metas as $locale => $values) {
            $meta = new FileVersionMeta();
            $meta->setLocale($locale);
            $meta->setTitle($values['title']);
            $meta->setDescription($values['description']);
            $meta->setFileVersion($fileVersion);
            $fileVersion->addMeta($meta);
        }

        $file = new File();
        $file->setVersion(1);
        $file->setMedia($media);
        $file->addFileVersion($fileVersion);
        $media->addFile($file);

        return $media;
    }

    public function testMissingModeFillsEmptyLocales(): void
    {
        $manager = $this->createMock(MediaManagerInterface::class);
        $saved = [];
        $manager->method('save')->willReturnCallback(
            static function ($uploadedFile, array $data) use (&$saved) {
                $saved[] = $data;

                return null;
            }
        );

        // de: filename title + no description -> both written. en: no meta at all -> both written.
        $media = $this->media(['de' => ['title' => 'DSC_1234.jpg', 'description' => null]]);
        $written = (new MediaMetaWriter($manager))->write($media, self::GENERATED, false, 7);

        $this->assertSame(['de', 'en'], \array_keys($written));
        $this->assertSame(
            ['id' => 42, 'locale' => 'de', 'title' => 'Pool', 'description' => 'Aussenpool.'],
            $saved[0]
        );
    }

    public function testMissingModeNeverOverwritesCuratedValues(): void
    {
        $manager = $this->createMock(MediaManagerInterface::class);
        $manager->expects($this->never())->method('save');

        $media = $this->media([
            'de' => ['title' => 'Poolbereich', 'description' => 'Vorhandene Beschreibung.'],
            'en' => ['title' => 'Pool area', 'description' => 'Existing description.'],
        ]);
        $written = (new MediaMetaWriter($manager))->write($media, self::GENERATED, false, 7);

        $this->assertSame([], $written);
    }

    public function testMissingModeKeepsCuratedTitleButFillsDescription(): void
    {
        $manager = $this->createMock(MediaManagerInterface::class);
        $saved = [];
        $manager->method('save')->willReturnCallback(
            static function ($uploadedFile, array $data) use (&$saved) {
                $saved[] = $data;

                return null;
            }
        );

        $media = $this->media([
            'de' => ['title' => 'Poolbereich', 'description' => ''],
            'en' => ['title' => 'Pool area', 'description' => 'Existing description.'],
        ]);
        $written = (new MediaMetaWriter($manager))->write($media, self::GENERATED, false, 7);

        $this->assertSame(['de' => ['description' => 'Aussenpool.']], $written);
        $this->assertSame(['id' => 42, 'locale' => 'de', 'description' => 'Aussenpool.'], $saved[0]);
    }

    public function testFilenameTitleMatchesWithoutExtension(): void
    {
        $manager = $this->createMock(MediaManagerInterface::class);
        $manager->method('save')->willReturn(null);

        // Sulu often strips the extension when deriving the initial title.
        $media = $this->media(['de' => ['title' => 'dsc_1234', 'description' => 'Da.']], 'DSC_1234.jpg');
        $written = (new MediaMetaWriter($manager))
            ->write($media, ['de' => self::GENERATED['de']], false, 7);

        $this->assertSame(['de' => ['title' => 'Pool']], $written);
    }

    public function testOverrideModeWritesEverything(): void
    {
        $manager = $this->createMock(MediaManagerInterface::class);
        $manager->expects($this->exactly(2))->method('save');

        $media = $this->media([
            'de' => ['title' => 'Poolbereich', 'description' => 'Vorhandene Beschreibung.'],
            'en' => ['title' => 'Pool area', 'description' => 'Existing description.'],
        ]);
        $written = (new MediaMetaWriter($manager))->write($media, self::GENERATED, true, 7);

        $this->assertSame(
            ['title' => 'Pool', 'description' => 'Aussenpool.'],
            $written['de']
        );
    }

    public function testExistingMetaIsExtractedPerLocale(): void
    {
        $manager = $this->createMock(MediaManagerInterface::class);
        $media = $this->media(['de' => ['title' => 'Poolbereich', 'description' => null]]);

        $existing = (new MediaMetaWriter($manager))->existingMeta($media, ['de', 'en']);

        $this->assertSame(
            ['de' => ['title' => 'Poolbereich', 'description' => ''], 'en' => ['title' => '', 'description' => '']],
            $existing
        );
    }
}
