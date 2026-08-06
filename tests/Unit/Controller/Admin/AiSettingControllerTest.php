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

    public function testPutStoresBrandingFields(): void
    {
        $setting = new AiSetting();

        $controller = $this->settingController($setting);
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
            'agentName' => 'KULM Concierge',
            'personality' => 'friendly',
            'customPrompt' => 'Always mention the spa.',
        ]));

        $this->assertSame('KULM Concierge', $setting->getAgentName());
        $this->assertSame('friendly', $setting->getPersonality());
        $this->assertSame('Always mention the spa.', $setting->getCustomPrompt());
    }

    public function testPutStoresEmptyBrandingFieldsAsNull(): void
    {
        $setting = new AiSetting();
        $setting->setAgentName('Old');
        $setting->setCustomPrompt('Old prompt');

        $controller = $this->settingController($setting);
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
            'agentName' => '',
            'personality' => 'neutral',
            'customPrompt' => '  ',
        ]));

        $this->assertNull($setting->getAgentName());
        $this->assertSame('neutral', $setting->getPersonality());
        $this->assertNull($setting->getCustomPrompt());
    }

    public function testPutWithoutBrandingKeysKeepsExistingBranding(): void
    {
        $setting = new AiSetting();
        $setting->setAgentName('KULM Concierge');
        $setting->setPersonality('formal');
        $setting->setCustomPrompt('Always mention the spa.');

        $controller = $this->settingController($setting);
        // An old-shape payload without branding keys must not wipe saved branding.
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
        ]));

        $this->assertSame('KULM Concierge', $setting->getAgentName());
        $this->assertSame('formal', $setting->getPersonality());
        $this->assertSame('Always mention the spa.', $setting->getCustomPrompt());
    }

    public function testPutStoresDataQueryTables(): void
    {
        $setting = new AiSetting();

        $controller = $this->settingController($setting);
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
            'dataQueryTables' => "fo_forms\nfo_dynamics",
        ]));

        $this->assertSame("fo_forms\nfo_dynamics", $setting->getDataQueryTables());
    }

    public function testPutStoresBlankDataQueryTablesAsNull(): void
    {
        $setting = new AiSetting();
        $setting->setDataQueryTables('fo_dynamics');

        $controller = $this->settingController($setting);
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
            'dataQueryTables' => '  ',
        ]));

        $this->assertNull($setting->getDataQueryTables());
    }

    public function testPutWithoutDataQueryKeyKeepsExistingTables(): void
    {
        $setting = new AiSetting();
        $setting->setDataQueryTables('fo_dynamics');

        $controller = $this->settingController($setting);
        // An old-shape payload without the key must not wipe the allowlist.
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
        ]));

        $this->assertSame('fo_dynamics', $setting->getDataQueryTables());
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

    public function testPutStoresMediaMetaModel(): void
    {
        $setting = new AiSetting();

        $controller = $this->settingController($setting);
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
            'mediaMetaModel' => '  gemini/gemini-3.1-flash  ',
        ]));

        $this->assertSame('gemini/gemini-3.1-flash', $setting->getMediaMetaModel());
    }

    public function testPutStoresBlankMediaMetaModelAsNull(): void
    {
        $setting = new AiSetting();
        $setting->setMediaMetaModel('gemini/gemini-3.1-flash');

        $controller = $this->settingController($setting);
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
            'mediaMetaModel' => '  ',
        ]));

        $this->assertNull($setting->getMediaMetaModel());
    }

    public function testPutWithoutMediaMetaModelKeyKeepsExistingValue(): void
    {
        $setting = new AiSetting();
        $setting->setMediaMetaModel('gemini/gemini-3.1-flash');

        $controller = $this->settingController($setting);
        // An old-shape payload without the key must not wipe the model.
        $controller->putAction(new Request(request: [
            'apiUrl' => 'https://api.test/v1',
            'model' => 'gpt-test',
            'enabled' => true,
        ]));

        $this->assertSame('gemini/gemini-3.1-flash', $setting->getMediaMetaModel());
    }
}
