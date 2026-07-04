<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\ImageGeneration;

use Marcostastny\SuluAIBundle\Service\ImageGeneration\ImagePromptBuilder;
use PHPUnit\Framework\TestCase;

class ImagePromptBuilderTest extends TestCase
{
    public function testBuildPromptCombinesAllParts(): void
    {
        $builder = new ImagePromptBuilder();
        $result = $builder->buildPrompt('A cat on a sofa', 'photorealistic', 'web', 'Brand: warm tones.');

        $this->assertStringContainsString('A cat on a sofa', $result);
        $this->assertStringContainsString('photorealistic', strtolower($result));
        $this->assertStringContainsString('web', strtolower($result));
        $this->assertStringContainsString('Brand: warm tones.', $result);
    }

    public function testBuildPromptOmitsEmptyParts(): void
    {
        $builder = new ImagePromptBuilder();
        $result = $builder->buildPrompt('Just the prompt', null, null, null);

        $this->assertSame('Just the prompt', trim($result));
    }

    public function testBuildPromptIgnoresUnknownStyleKey(): void
    {
        $builder = new ImagePromptBuilder();
        $result = $builder->buildPrompt('Prompt', 'not-a-style', null, null);

        $this->assertSame('Prompt', trim($result));
    }

    public function testBuildSizeMapsFormatToGptImageSizes(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertSame('1536x1024', $builder->buildSize('16:9'));
        $this->assertSame('1024x1536', $builder->buildSize('9:16'));
        $this->assertSame('1024x1024', $builder->buildSize('1:1'));
    }

    public function testBuildSizeFallsBackToSquare(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertSame('1024x1024', $builder->buildSize(null));
        $this->assertSame('1024x1024', $builder->buildSize('unknown'));
    }

    public function testBuildQualityMapsResolution(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertSame('medium', $builder->buildQuality('standard'));
        $this->assertSame('high', $builder->buildQuality('high'));
        $this->assertSame('medium', $builder->buildQuality(null));
    }
}
