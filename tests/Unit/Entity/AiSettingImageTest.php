<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Entity;

use Marcostastny\SuluAIBundle\Entity\AiSetting;
use PHPUnit\Framework\TestCase;

class AiSettingImageTest extends TestCase
{
    public function testImageModelsRoundtrip(): void
    {
        $models = [
            ['label' => 'GPT Image 2', 'modelId' => 'gpt-image-2', 'supportsReference' => true, 'maxImages' => 4],
        ];
        $setting = new AiSetting();
        $setting->setImageModels($models);

        $this->assertSame($models, $setting->getImageModels());
    }

    public function testImageModelsDefaultsToEmptyArray(): void
    {
        $this->assertSame([], (new AiSetting())->getImageModels());
    }

    public function testImageStylePromptRoundtrip(): void
    {
        $setting = new AiSetting();
        $setting->setImageStylePrompt('Corporate look, muted colors.');

        $this->assertSame('Corporate look, muted colors.', $setting->getImageStylePrompt());
    }

    public function testImageGenerationSecurityContextConstant(): void
    {
        $this->assertSame('sulu_ai.image_generation', AiSetting::SECURITY_CONTEXT_IMAGE_GENERATION);
    }

    public function testIsConfiguredRequiresChatModelByDefault(): void
    {
        $setting = new AiSetting();
        $setting->setEnabled(true);
        $setting->setApiUrl('https://api.test/v1');
        $setting->setApiKey('key');

        $this->assertFalse($setting->isConfigured());
        $this->assertTrue($setting->isConfigured(false));

        $setting->setModel('gpt-test');
        $this->assertTrue($setting->isConfigured());
    }

    public function testIsConfiguredFalseWhenDisabledOrIncomplete(): void
    {
        $setting = new AiSetting();
        $setting->setApiUrl('https://api.test/v1');
        $setting->setApiKey('key');
        $setting->setModel('gpt-test');

        $this->assertFalse($setting->isConfigured(), 'disabled setting is not configured');

        $setting->setEnabled(true);
        $setting->setApiKey(null);
        $this->assertFalse($setting->isConfigured(false), 'missing api key is not configured');
    }
}
