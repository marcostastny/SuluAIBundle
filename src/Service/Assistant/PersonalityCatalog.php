<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

/**
 * Precreated assistant personalities. Keys are stored in AiSetting::$personality
 * and offered by the settings form; the instruction texts are model-facing and
 * therefore English regardless of the admin locale.
 */
final class PersonalityCatalog
{
    public const KEYS = ['professional', 'friendly', 'concise', 'playful', 'formal'];

    private const INSTRUCTIONS = [
        'professional' => 'Maintain a polished, courteous, business-like tone. Be competent and respectful without being stiff.',
        'friendly' => 'Use a warm, approachable and encouraging tone. Be conversational and personal.',
        'concise' => 'Keep answers short and direct. No filler and no repetition of the question - get straight to the point.',
        'playful' => 'Use light humor and casual language where appropriate, but never at the expense of clarity or correctness.',
        'formal' => 'Stay reserved and precise. Use formal address (use "Sie" when writing German).',
    ];

    public static function instruction(?string $key): ?string
    {
        if (null === $key) {
            return null;
        }

        return self::INSTRUCTIONS[$key] ?? null;
    }
}
