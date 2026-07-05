<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use FOS\RestBundle\View\View;
use FOS\RestBundle\View\ViewHandlerInterface;
use Marcostastny\SuluAIBundle\Controller\Admin\AiSettingController;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AiSettingControllerTest extends TestCase
{
    public function testPutStoresImageModelsWithBlockType(): void
    {
        $setting = new AiSetting();

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $viewHandler = $this->createMock(ViewHandlerInterface::class);
        $viewHandler->method('handle')->willReturnCallback(
            static fn (View $view): Response => new Response()
        );

        $controller = new AiSettingController($entityManager, $viewHandler);

        $request = new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'apiKey' => 'key',
            'model' => 'gpt-test',
            'enabled' => true,
            'imageStylePrompt' => 'Brand look',
            'imageModels' => [
                ['type' => 'model', 'label' => 'GPT Image 2', 'modelId' => 'gpt-image-2', 'supportsReference' => true, 'maxImages' => 2],
                ['type' => 'model', 'modelId' => '', 'label' => 'skip me'],
            ],
        ]);

        $controller->putAction($request);

        $stored = $setting->getImageModels();
        $this->assertCount(1, $stored);
        $this->assertSame('model', $stored[0]['type']);
        $this->assertSame('gpt-image-2', $stored[0]['modelId']);
        $this->assertTrue($stored[0]['supportsReference']);
        $this->assertSame(2, $stored[0]['maxImages']);
        $this->assertSame('Brand look', $setting->getImageStylePrompt());
    }

    public function testPutKeepsExistingApiKeyWhenSubmittedEmpty(): void
    {
        $setting = new AiSetting();
        $setting->setApiKey('stored-secret');

        $controller = $this->settingController($setting);
        // The write-only field arrives empty when unchanged; must not wipe.
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
            'apiKey' => '',
        ]));

        $this->assertSame('stored-secret', $setting->getApiKey());
    }

    public function testPutUpdatesApiKeyWhenNonEmpty(): void
    {
        $setting = new AiSetting();
        $setting->setApiKey('old-secret');

        $controller = $this->settingController($setting);
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
            'apiKey' => 'new-secret',
        ]));

        $this->assertSame('new-secret', $setting->getApiKey());
    }

    public function testApiKeyIsNotSerializedButApiKeySetIs(): void
    {
        $setting = new AiSetting();
        $setting->setApiKey('secret');

        $this->assertTrue($setting->hasApiKey());
    }

    private function settingController(AiSetting $setting): AiSettingController
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $viewHandler = $this->createMock(ViewHandlerInterface::class);
        $viewHandler->method('handle')->willReturnCallback(static fn (View $view): Response => new Response());

        return new AiSettingController($entityManager, $viewHandler);
    }

    public function testPutWithoutImageKeysKeepsExistingImageConfig(): void
    {
        $setting = new AiSetting();
        $setting->setImageModels([
            ['type' => 'model', 'label' => 'GPT Image 2', 'modelId' => 'gpt-image-2', 'supportsReference' => true, 'maxImages' => 4],
        ]);
        $setting->setImageStylePrompt('Brand look');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $viewHandler = $this->createMock(ViewHandlerInterface::class);
        $viewHandler->method('handle')->willReturnCallback(
            static fn (View $view): Response => new Response()
        );

        $controller = new AiSettingController($entityManager, $viewHandler);

        // An old-shape payload without image keys must not wipe saved image config.
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'apiKey' => 'key',
            'model' => 'gpt-test',
            'enabled' => true,
        ]));

        $this->assertCount(1, $setting->getImageModels());
        $this->assertSame('gpt-image-2', $setting->getImageModels()[0]['modelId']);
        $this->assertSame('Brand look', $setting->getImageStylePrompt());
    }
}
