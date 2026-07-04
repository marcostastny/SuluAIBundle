<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Controller\Admin\ImageGenerationController;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\ImageGeneration\AiCreatedCollection;
use Marcostastny\SuluAIBundle\Service\ImageGeneration\GeneratedImageSaver;
use Marcostastny\SuluAIBundle\Service\ImageGeneration\ImagePromptBuilder;
use Marcostastny\SuluAIBundle\Service\ImageGeneration\OpenAiImageGenerator;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ImageGenerationControllerTest extends TestCase
{
    private function setting(): AiSetting
    {
        $setting = new AiSetting();
        $setting->setEnabled(true);
        $setting->setApiUrl('https://api.test/v1');
        $setting->setApiKey('key');
        $setting->setModel('gpt-test');
        $setting->setImageModels([
            ['label' => 'GPT Image 2', 'modelId' => 'gpt-image-2', 'supportsReference' => true, 'maxImages' => 4],
            ['label' => 'Flux', 'modelId' => 'flux-2-pro', 'supportsReference' => false, 'maxImages' => 4],
        ]);

        return $setting;
    }

    private function controller(
        ?AiSetting $setting,
        ?OpenAiImageGenerator $generator = null,
        ?AiCreatedCollection $collection = null,
        ?GeneratedImageSaver $saver = null,
        ?SecurityCheckerInterface $securityChecker = null
    ): ImageGenerationController {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new ImageGenerationController(
            $entityManager,
            new ImagePromptBuilder(),
            $generator ?? $this->createMock(OpenAiImageGenerator::class),
            $collection ?? $this->createMock(AiCreatedCollection::class),
            $saver ?? $this->createMock(GeneratedImageSaver::class),
            $securityChecker ?? $this->createMock(SecurityCheckerInterface::class),
            null
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload): Request
    {
        return new Request(content: (string) \json_encode($payload));
    }

    public function testDeniedWithoutPermission(): void
    {
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('checkPermission')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);

        $this->controller($this->setting(), null, null, null, $securityChecker)
            ->postAction($this->request(['modelId' => 'gpt-image-2', 'prompt' => 'a cat']));
    }

    public function testUnknownModelReturns400(): void
    {
        $response = $this->controller($this->setting())
            ->postAction($this->request(['modelId' => 'nope', 'prompt' => 'a cat']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testReferencesToNonSupportingModelReturns400(): void
    {
        $response = $this->controller($this->setting())->postAction($this->request([
            'modelId' => 'flux-2-pro',
            'prompt' => 'a cat',
            'references' => [['filename' => 'r.png', 'contentType' => 'image/png', 'data' => base64_encode('x')]],
        ]));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testNotConfiguredReturns400(): void
    {
        $response = $this->controller(null)
            ->postAction($this->request(['modelId' => 'gpt-image-2', 'prompt' => 'a cat']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHappyPathReturnsSavedImages(): void
    {
        $generator = $this->createMock(OpenAiImageGenerator::class);
        $generator->method('generate')->willReturn([['b64' => 'AAA', 'url' => null]]);

        $collection = $this->createMock(AiCreatedCollection::class);
        $collection->method('ensure')->willReturn(3);

        $saver = $this->createMock(GeneratedImageSaver::class);
        $saver->method('save')->willReturn(['id' => 10, 'thumbnailUrl' => '/t.jpg', 'title' => 'a cat']);

        $response = $this->controller($this->setting(), $generator, $collection, $saver)->postAction($this->request([
            'modelId' => 'gpt-image-2',
            'prompt' => 'a cat',
            'style' => 'photorealistic',
            'format' => '16:9',
            'resolution' => 'high',
            'count' => 1,
            'locale' => 'de',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertSame(10, $decoded['images'][0]['id']);
    }

    public function testCountClampedToModelMaxImages(): void
    {
        $capturedCount = null;
        $generator = $this->createMock(OpenAiImageGenerator::class);
        $generator->method('generate')->willReturnCallback(
            function ($apiUrl, $apiKey, $modelId, $prompt, $size, $count) use (&$capturedCount): array {
                $capturedCount = $count;

                return [];
            }
        );

        $collection = $this->createMock(AiCreatedCollection::class);
        $collection->method('ensure')->willReturn(3);

        $setting = new AiSetting();
        $setting->setEnabled(true);
        $setting->setApiUrl('https://api.test/v1');
        $setting->setApiKey('key');
        $setting->setImageModels([
            ['label' => 'Capped', 'modelId' => 'gpt-image-2', 'supportsReference' => false, 'maxImages' => 1],
        ]);

        $response = $this->controller($setting, $generator, $collection)->postAction($this->request([
            'modelId' => 'gpt-image-2',
            'prompt' => 'a cat',
            'count' => 4,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $capturedCount, 'count must be clamped to the model\'s maxImages');
    }

    public function testGenerationFailureReturns502(): void
    {
        $generator = $this->createMock(OpenAiImageGenerator::class);
        $generator->method('generate')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller($this->setting(), $generator)->postAction($this->request([
            'modelId' => 'gpt-image-2',
            'prompt' => 'a cat',
        ]));

        $this->assertSame(502, $response->getStatusCode());
    }
}
