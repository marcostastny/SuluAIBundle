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

        $this->assertSame('1536x1024', $builder->buildSize('16:9', 'gpt-image-2'));
        $this->assertSame('1024x1536', $builder->buildSize('9:16', 'gpt-image-2'));
        $this->assertSame('1024x1024', $builder->buildSize('1:1', 'gpt-image-2'));
    }

    public function testBuildSizeFallsBackToSquare(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertSame('1024x1024', $builder->buildSize(null, 'gpt-image-2'));
        $this->assertSame('1024x1024', $builder->buildSize('unknown', 'gpt-image-2'));
    }

    public function testBuildSizeUsesDalleSizesForDalleModels(): void
    {
        $builder = new ImagePromptBuilder();

        // DALL·E 3 landscape is 1792x1024, not gpt-image's 1536x1024.
        $this->assertSame('1792x1024', $builder->buildSize('16:9', 'dall-e-3'));
        $this->assertSame('1024x1792', $builder->buildSize('9:16', 'azure/dall-e-3'));
    }

    public function testBuildSizeFallsBackToSquareForUnknownModels(): void
    {
        $builder = new ImagePromptBuilder();

        // A model of unknown family only gets the universally accepted square.
        $this->assertSame('1024x1024', $builder->buildSize('16:9', 'flux-2-pro'));
    }

    public function testBuildQualityMapsResolutionForGptImage(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertSame('medium', $builder->buildQuality('standard', 'gpt-image-2'));
        $this->assertSame('high', $builder->buildQuality('high', 'gpt-image-2'));
        $this->assertSame('medium', $builder->buildQuality(null, 'gpt-image-2'));
    }

    public function testBuildQualityUsesDalleValues(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertSame('standard', $builder->buildQuality('standard', 'dall-e-3'));
        $this->assertSame('hd', $builder->buildQuality('high', 'dall-e-3'));
    }

    public function testBuildQualityNullForModelsWithoutQuality(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertNull($builder->buildQuality('high', 'dall-e-2'));
        $this->assertNull($builder->buildQuality('high', 'flux-2-pro'));
    }
}
