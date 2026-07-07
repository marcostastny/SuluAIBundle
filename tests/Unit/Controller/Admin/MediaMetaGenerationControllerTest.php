<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Controller\Admin\MediaMetaGenerationController;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaFinder;
use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaGenerator;
use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaWriter;
use Marcostastny\SuluAIBundle\Service\MediaMeta\PreviewNotSupportedException;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Localization\Manager\LocalizationManagerInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class MediaMetaGenerationControllerTest extends TestCase
{
    private const GENERATED = [
        'de' => ['title' => 'Pool', 'description' => 'Aussenpool.'],
        'en' => ['title' => 'Pool', 'description' => 'Outdoor pool.'],
    ];

    private MediaMetaFinder $finder;
    private MediaMetaGenerator $generator;
    private SecurityCheckerInterface $securityChecker;
    private EntityRepository $mediaRepository;

    private function setting(): AiSetting
    {
        $setting = new AiSetting();
        $setting->setEnabled(true);
        $setting->setApiUrl('https://api.test/v1');
        $setting->setApiKey('key');
        $setting->setModel('gpt-test');

        return $setting;
    }

    private function mediaEntity(int $id, string $mimeType = 'image/jpeg'): Media
    {
        $media = new Media();
        $reflection = new \ReflectionProperty(Media::class, 'id');
        $reflection->setValue($media, $id);

        $fileVersion = new FileVersion();
        $fileVersion->setName('img-' . $id . '.jpg');
        $fileVersion->setVersion(1);
        $fileVersion->setMimeType($mimeType);

        $file = new File();
        $file->setVersion(1);
        $file->setMedia($media);
        $file->addFileVersion($fileVersion);
        $media->addFile($file);

        return $media;
    }

    private function controller(?AiSetting $setting): MediaMetaGenerationController
    {
        $settingRepository = $this->createMock(EntityRepository::class);
        $settingRepository->method('findOneBy')->willReturn($setting);
        $this->mediaRepository = $this->createMock(EntityRepository::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            fn (string $class) => Media::class === $class ? $this->mediaRepository : $settingRepository
        );

        $this->finder = $this->createMock(MediaMetaFinder::class);
        $this->generator = $this->createMock(MediaMetaGenerator::class);
        $writer = new MediaMetaWriter($this->createMock(MediaManagerInterface::class));
        $this->securityChecker = $this->createMock(SecurityCheckerInterface::class);

        $localizationManager = $this->createMock(LocalizationManagerInterface::class);
        $localizationManager->method('getLocales')->willReturn(['de', 'en']);

        $user = new User();
        $userReflection = new \ReflectionProperty(User::class, 'id');
        $userReflection->setValue($user, 7);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return new MediaMetaGenerationController(
            $entityManager,
            $this->finder,
            $this->generator,
            $writer,
            $this->securityChecker,
            $tokenStorage,
            $localizationManager
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(array $payload): Request
    {
        return new Request(content: (string) \json_encode($payload));
    }

    public function testMissingCountRequiresPermissions(): void
    {
        $controller = $this->controller($this->setting());
        $this->securityChecker->method('checkPermission')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);

        $controller->getMissingCountAction();
    }

    public function testMissingCountReturnsFinderCount(): void
    {
        $controller = $this->controller($this->setting());
        $this->finder->method('count')->with(['de', 'en'])->willReturn(12);

        $response = $controller->getMissingCountAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['count' => 12], \json_decode((string) $response->getContent(), true));
    }

    public function testNotConfiguredReturns400(): void
    {
        $response = $this->controller(null)->postBatchAction($this->jsonRequest(['mode' => 'missing']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testInvalidModeReturns400(): void
    {
        $response = $this->controller($this->setting())->postBatchAction($this->jsonRequest(['mode' => 'nope']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSelectedModeWithoutIdsReturns400(): void
    {
        $response = $this->controller($this->setting())
            ->postBatchAction($this->jsonRequest(['mode' => 'selected', 'ids' => []]));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMissingModeProcessesFinderBatchAndReturnsRemaining(): void
    {
        $controller = $this->controller($this->setting());
        $this->finder->method('findIds')->with(['de', 'en'], 5, [3])->willReturn([1]);
        $this->finder->method('count')->willReturn(4);
        $this->mediaRepository->method('findBy')->willReturn([$this->mediaEntity(1)]);
        $this->generator->method('generate')->willReturn(self::GENERATED);

        $response = $controller->postBatchAction(
            $this->jsonRequest(['mode' => 'missing', 'excludeIds' => [3]])
        );

        $this->assertSame(200, $response->getStatusCode());
        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertSame(1, $decoded['processed'][0]['id']);
        $this->assertSame(['de', 'en'], \array_keys($decoded['processed'][0]['locales']));
        $this->assertSame([], $decoded['errors']);
        $this->assertSame(4, $decoded['remaining']);
    }

    public function testMissingModeExcludesSkippedIdsFromRemaining(): void
    {
        $controller = $this->controller($this->setting());
        // The finder selects id 1 (its meta IS missing), but the preview
        // cannot be rendered -> skipped. The remaining count must exclude it,
        // or the client would loop over the same image forever.
        $this->finder->method('findIds')->willReturn([1]);
        $this->finder->expects($this->once())->method('count')->with(['de', 'en'], [1])->willReturn(0);
        $this->mediaRepository->method('findBy')->willReturn([$this->mediaEntity(1)]);
        $this->generator->method('generate')->willThrowException(new PreviewNotSupportedException('gone'));

        $response = $controller->postBatchAction($this->jsonRequest(['mode' => 'missing']));

        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertSame([['id' => 1, 'reason' => 'no-preview']], $decoded['skipped']);
        $this->assertSame(0, $decoded['remaining']);
    }

    public function testSelectedModeCapsAtFiveAndSkipsNonImages(): void
    {
        $controller = $this->controller($this->setting());
        // 6 ids sent, only 5 loaded; id 2 is a PDF -> skipped.
        $this->mediaRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => \array_map(
                fn (int $id): Media => $this->mediaEntity($id, 2 === $id ? 'application/pdf' : 'image/jpeg'),
                $criteria['id']
            )
        );
        $this->generator->method('generate')->willReturn(self::GENERATED);

        $response = $controller->postBatchAction(
            $this->jsonRequest(['mode' => 'selected', 'ids' => [1, 2, 3, 4, 5, 6]])
        );

        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertCount(4, $decoded['processed']);
        $this->assertSame([['id' => 2, 'reason' => 'not-an-image']], $decoded['skipped']);
        $this->assertSame(0, $decoded['remaining']);
    }

    public function testGeneratorErrorIsCollectedPerImage(): void
    {
        $controller = $this->controller($this->setting());
        $this->mediaRepository->method('findBy')->willReturn([$this->mediaEntity(1), $this->mediaEntity(2)]);
        $this->generator->method('generate')->willReturnCallback(
            static function (string $apiUrl, string $apiKey, string $model, FileVersion $fileVersion): array {
                if ('img-1.jpg' === $fileVersion->getName()) {
                    throw new \RuntimeException('API returned status 429');
                }

                return self::GENERATED;
            }
        );

        $response = $controller->postBatchAction($this->jsonRequest(['mode' => 'selected', 'ids' => [1, 2]]));

        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertCount(1, $decoded['processed']);
        $this->assertSame(1, $decoded['errors'][0]['id']);
        $this->assertStringContainsString('429', $decoded['errors'][0]['message']);
    }

    public function testPreviewFailureIsSkippedNotErrored(): void
    {
        $controller = $this->controller($this->setting());
        $this->mediaRepository->method('findBy')->willReturn([$this->mediaEntity(1)]);
        $this->generator->method('generate')->willThrowException(new PreviewNotSupportedException('no preview'));

        $response = $controller->postBatchAction($this->jsonRequest(['mode' => 'selected', 'ids' => [1]]));

        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertSame([['id' => 1, 'reason' => 'no-preview']], $decoded['skipped']);
        $this->assertSame([], $decoded['errors']);
    }

    public function testUnknownIdIsSkipped(): void
    {
        $controller = $this->controller($this->setting());
        $this->mediaRepository->method('findBy')->willReturn([]);

        $response = $controller->postBatchAction($this->jsonRequest(['mode' => 'selected', 'ids' => [99]]));

        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertSame([['id' => 99, 'reason' => 'not-found']], $decoded['skipped']);
    }

    public function testGeneratorUsesDedicatedMediaMetaModelWhenConfigured(): void
    {
        $setting = $this->setting();
        $setting->setMediaMetaModel('gemini/vision-test');

        $controller = $this->controller($setting);
        $this->mediaRepository->method('findBy')->willReturn([$this->mediaEntity(1)]);

        $models = [];
        $this->generator->method('generate')->willReturnCallback(
            static function (string $apiUrl, string $apiKey, string $model) use (&$models): array {
                $models[] = $model;

                return self::GENERATED;
            }
        );

        $controller->postBatchAction($this->jsonRequest(['mode' => 'selected', 'ids' => [1]]));

        $this->assertSame(['gemini/vision-test'], $models);
    }

    public function testGeneratorFallsBackToChatModelWithoutMediaMetaModel(): void
    {
        $controller = $this->controller($this->setting());
        $this->mediaRepository->method('findBy')->willReturn([$this->mediaEntity(1)]);

        $models = [];
        $this->generator->method('generate')->willReturnCallback(
            static function (string $apiUrl, string $apiKey, string $model) use (&$models): array {
                $models[] = $model;

                return self::GENERATED;
            }
        );

        $controller->postBatchAction($this->jsonRequest(['mode' => 'selected', 'ids' => [1]]));

        $this->assertSame(['gpt-test'], $models);
    }
}
