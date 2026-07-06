<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Admin\AiSettingAdmin;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

class AiSettingAdminTest extends TestCase
{
    private function admin(?AiSetting $setting, bool $hasAssistantPermission): AiSettingAdmin
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn (mixed $context, string $permission): bool => \in_array($context, [
                AiSetting::SECURITY_CONTEXT_ASSISTANT,
                AiSetting::SECURITY_CONTEXT_IMAGE_GENERATION,
            ], true) && PermissionTypes::VIEW === $permission && $hasAssistantPermission
        );

        return new AiSettingAdmin(
            $this->createMock(ViewBuilderFactoryInterface::class),
            $securityChecker,
            $entityManager
        );
    }

    private function enabledSetting(): AiSetting
    {
        $setting = new AiSetting();
        $setting->setEnabled(true);
        $setting->setApiUrl('https://api.test/v1');
        $setting->setApiKey('key');
        $setting->setModel('gpt-test');
        $setting->setImageModels([
            ['label' => 'GPT Image 2', 'modelId' => 'gpt-image-2', 'supportsReference' => true, 'maxImages' => 4],
        ]);

        return $setting;
    }

    public function testConfigKey(): void
    {
        $this->assertSame('sulu_ai_bundle', $this->admin(null, false)->getConfigKey());
    }

    public function testAssistantAvailableWhenEnabledAndPermitted(): void
    {
        $config = $this->admin($this->enabledSetting(), true)->getConfig();

        $this->assertTrue($config['assistant']['available']);
    }

    public function testAssistantUnavailableWithoutPermission(): void
    {
        $config = $this->admin($this->enabledSetting(), false)->getConfig();

        $this->assertFalse($config['assistant']['available']);
    }

    public function testAssistantUnavailableWhenNotConfigured(): void
    {
        $this->assertFalse($this->admin(null, true)->getConfig()['assistant']['available']);

        $disabled = $this->enabledSetting();
        $disabled->setEnabled(false);
        $this->assertFalse($this->admin($disabled, true)->getConfig()['assistant']['available']);
    }

    public function testImageGenerationAvailableWhenEnabledAndPermitted(): void
    {
        $config = $this->admin($this->enabledSetting(), true)->getConfig();

        $this->assertTrue($config['imageGeneration']['available']);
        $this->assertSame('gpt-image-2', $config['imageGeneration']['models'][0]['id']);
        $this->assertTrue($config['imageGeneration']['models'][0]['supportsReference']);
    }

    public function testImageGenerationUnavailableWithoutPermission(): void
    {
        $config = $this->admin($this->enabledSetting(), false)->getConfig();

        $this->assertFalse($config['imageGeneration']['available']);
    }

    public function testImageGenerationModelsEmptyWhenUnconfigured(): void
    {
        $config = $this->admin(null, true)->getConfig();

        $this->assertFalse($config['imageGeneration']['available']);
        $this->assertSame([], $config['imageGeneration']['models']);
    }

    public function testImageGenerationModelsHiddenWithoutPermission(): void
    {
        $config = $this->admin($this->enabledSetting(), false)->getConfig();

        // The models list must not leak to users lacking the image permission.
        $this->assertSame([], $config['imageGeneration']['models']);
    }

    public function testConfigSurvivesMissingSchema(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willThrowException(new \RuntimeException('no such table: sulu_ai_settings'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $admin = new AiSettingAdmin(
            $this->createMock(ViewBuilderFactoryInterface::class),
            $this->createMock(SecurityCheckerInterface::class),
            $entityManager
        );

        $config = $admin->getConfig();

        $this->assertFalse($config['assistant']['available']);
        $this->assertFalse($config['imageGeneration']['available']);
        $this->assertSame([], $config['imageGeneration']['models']);
    }
}
