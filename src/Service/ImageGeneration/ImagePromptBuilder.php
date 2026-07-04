<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\ImageGeneration;

/**
 * Turns the form's structured options into a single prompt string and an
 * images-API size parameter. All option lists are built-in.
 */
class ImagePromptBuilder
{
    /** @var array<string, string> option value => prompt guidance */
    public const STYLES = [
        'photorealistic' => 'photorealistic style',
        'illustration' => 'illustration style',
        '3d' => '3D render style',
        'watercolor' => 'watercolor painting style',
        'minimal' => 'minimalist style',
        'product' => 'clean product-photography style',
    ];

    /** @var array<string, string> option value => prompt guidance */
    public const PURPOSES = [
        'web' => 'optimized for use on a website',
        'social' => 'optimized for social media',
        'print' => 'optimized for print',
        'presentation' => 'optimized for a presentation slide',
    ];

    /**
     * @var array<string, string> format => size
     *
     * gpt-image models accept 1024x1024, 1536x1024 (landscape), 1024x1536
     * (portrait) or auto. Map every format onto the closest supported size.
     */
    public const SIZES = [
        '1:1' => '1024x1024',
        '16:9' => '1536x1024',
        '9:16' => '1024x1536',
        '4:3' => '1536x1024',
        '3:2' => '1536x1024',
    ];

    /** @var array<string, string> resolution option => images-API quality */
    public const QUALITIES = [
        'standard' => 'medium',
        'high' => 'high',
    ];

    public function buildPrompt(
        string $prompt,
        ?string $style,
        ?string $purpose,
        ?string $companyStylePrompt
    ): string {
        $parts = [\trim($prompt)];

        if (null !== $style && isset(self::STYLES[$style])) {
            $parts[] = 'Style: ' . self::STYLES[$style] . '.';
        }
        if (null !== $purpose && isset(self::PURPOSES[$purpose])) {
            $parts[] = 'Purpose: ' . self::PURPOSES[$purpose] . '.';
        }
        if (null !== $companyStylePrompt && '' !== \trim($companyStylePrompt)) {
            $parts[] = \trim($companyStylePrompt);
        }

        return \implode("\n", \array_filter($parts, static fn (string $p): bool => '' !== $p));
    }

    public function buildSize(?string $format): string
    {
        return self::SIZES[$format ?? ''] ?? self::SIZES['1:1'];
    }

    public function buildQuality(?string $resolution): string
    {
        return self::QUALITIES[$resolution ?? ''] ?? self::QUALITIES['standard'];
    }
}
