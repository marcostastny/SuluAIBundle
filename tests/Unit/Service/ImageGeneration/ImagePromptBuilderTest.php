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

    public function testBuildSizeMapsFormatAndResolution(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertSame('1792x1024', $builder->buildSize('16:9', 'high'));
        $this->assertSame('1024x1024', $builder->buildSize('1:1', 'standard'));
    }

    public function testBuildSizeFallsBackToSquareStandard(): void
    {
        $builder = new ImagePromptBuilder();

        $this->assertSame('1024x1024', $builder->buildSize(null, null));
        $this->assertSame('1024x1024', $builder->buildSize('unknown', 'unknown'));
    }
}
