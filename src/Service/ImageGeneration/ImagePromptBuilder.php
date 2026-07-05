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
     * Per-family size maps keyed by orientation. Different model families accept
     * different sizes, so the size is chosen for the selected model's family
     * rather than assuming gpt-image everywhere.
     *
     * @var array<string, array<string, string>>
     */
    private const FAMILY_SIZES = [
        'gpt-image' => ['square' => '1024x1024', 'landscape' => '1536x1024', 'portrait' => '1024x1536'],
        'dalle3' => ['square' => '1024x1024', 'landscape' => '1792x1024', 'portrait' => '1024x1792'],
        'dalle2' => ['square' => '1024x1024', 'landscape' => '1024x1024', 'portrait' => '1024x1024'],
        // Unknown/other providers: the universally accepted square only.
        'generic' => ['square' => '1024x1024', 'landscape' => '1024x1024', 'portrait' => '1024x1024'],
    ];

    /**
     * Per-family quality maps (resolution option => API quality). A null entry
     * means the family has no quality parameter, so none is sent.
     *
     * @var array<string, array<string, string>|null>
     */
    private const FAMILY_QUALITIES = [
        'gpt-image' => ['standard' => 'medium', 'high' => 'high'],
        'dalle3' => ['standard' => 'standard', 'high' => 'hd'],
        'dalle2' => null,
        'generic' => null,
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

    public function buildSize(?string $format, string $modelId): string
    {
        $sizes = self::FAMILY_SIZES[$this->family($modelId)];

        return $sizes[$this->orientation($format)];
    }

    public function buildQuality(?string $resolution, string $modelId): ?string
    {
        $qualities = self::FAMILY_QUALITIES[$this->family($modelId)];
        if (null === $qualities) {
            return null;
        }

        return $qualities[$resolution ?? ''] ?? $qualities['standard'];
    }

    private function orientation(?string $format): string
    {
        return match ($format) {
            '9:16' => 'portrait',
            '16:9', '4:3', '3:2' => 'landscape',
            default => 'square',
        };
    }

    /**
     * Infers the model family from the configured model id. LiteLLM route names
     * often carry a provider prefix (e.g. "azure/dall-e-3"), so substring checks
     * are used; unrecognised ids fall back to the safe "generic" family.
     */
    private function family(string $modelId): string
    {
        $id = \strtolower($modelId);
        if (\str_contains($id, 'gpt-image')) {
            return 'gpt-image';
        }
        if (\str_contains($id, 'dall-e-2') || \str_contains($id, 'dalle2')) {
            return 'dalle2';
        }
        if (\str_contains($id, 'dall-e-3') || \str_contains($id, 'dalle3')) {
            return 'dalle3';
        }

        return 'generic';
    }
}
