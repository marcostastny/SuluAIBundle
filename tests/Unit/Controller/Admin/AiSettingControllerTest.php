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
}
