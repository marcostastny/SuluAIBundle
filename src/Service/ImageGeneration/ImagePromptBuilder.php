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

    /** @var array<string, array<string, string>> format => resolution => size */
    public const SIZES = [
        '1:1' => ['standard' => '1024x1024', 'high' => '1024x1024'],
        '16:9' => ['standard' => '1792x1024', 'high' => '1792x1024'],
        '9:16' => ['standard' => '1024x1792', 'high' => '1024x1792'],
        '4:3' => ['standard' => '1024x1024', 'high' => '1024x1024'],
        '3:2' => ['standard' => '1024x1024', 'high' => '1024x1024'],
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

    public function buildSize(?string $format, ?string $resolution): string
    {
        $byResolution = self::SIZES[$format ?? ''] ?? self::SIZES['1:1'];

        return $byResolution[$resolution ?? ''] ?? $byResolution['standard'];
    }
}
