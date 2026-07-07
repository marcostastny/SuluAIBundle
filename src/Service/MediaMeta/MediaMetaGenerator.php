<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\MediaMeta;

use Marcostastny\SuluAIBundle\Service\OpenAiClient;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\ImageConverterInterface;

/**
 * Generates per-locale media title + description (used as alt text by the
 * website) with ONE vision chat-completion per image: a small preview render
 * is sent as a base64 data URI, the reply is a JSON object keyed by locale.
 */
class MediaMetaGenerator
{
    /**
     * Small preview format: enough detail to describe the image, cheap to
     * send. Internal Sulu format, always defined.
     */
    private const PREVIEW_FORMAT = 'sulu-400x400';
    private const PREVIEW_IMAGE_FORMAT = 'jpg';

    /**
     * Vision completions with several locales can be slow; cover them with
     * the same generous idle-timeout style as image generation.
     */
    private const REQUEST_OPTIONS = [
        'timeout' => 120,
        'max_duration' => 140,
    ];

    public function __construct(
        private OpenAiClient $client,
        private ImageConverterInterface $converter,
    ) {
    }

    /**
     * @param string[] $locales
     * @param array<string, array{title: string, description: string}> $existing
     *
     * @return array<string, array{title: string, description: string}>
     *
     * @throws PreviewNotSupportedException when no preview can be rendered
     * @throws \RuntimeException on API errors or an unusable reply
     */
    public function generate(
        string $apiUrl,
        string $apiKey,
        string $model,
        FileVersion $fileVersion,
        array $locales,
        array $existing = []
    ): array {
        try {
            $binary = (string) $this->converter->convert($fileVersion, self::PREVIEW_FORMAT, self::PREVIEW_IMAGE_FORMAT);
        } catch (\Throwable $e) {
            throw new PreviewNotSupportedException(
                \sprintf('Preview for "%s" could not be rendered: %s', $fileVersion->getName(), $e->getMessage()),
                0,
                $e
            );
        }

        $data = $this->client->postJson($apiUrl, $apiKey, '/chat/completions', [
            'model' => $model,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($locales)],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $this->userPrompt($fileVersion->getName(), $existing)],
                    ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:image/jpeg;base64,' . \base64_encode($binary),
                    ]],
                ]],
            ],
        ], self::REQUEST_OPTIONS);

        $content = (string) ($data['choices'][0]['message']['content'] ?? '');

        return $this->parseReply($content, $locales);
    }

    /**
     * @param string[] $locales
     *
     * @return array<string, array{title: string, description: string}>
     */
    private function parseReply(string $reply, array $locales): array
    {
        // Strip a markdown code fence, which some OpenAI-compatible models
        // add despite response_format (same tolerance as OpenAiMetaGenerator).
        $stripped = \trim((string) \preg_replace('/```[a-zA-Z]*\n?|```/', '', $reply));

        $decoded = \json_decode($stripped, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf(
                'AI reply was not a JSON object. Reply was: %s',
                '' === \trim($reply) ? '(empty)' : \mb_substr($reply, 0, 200)
            ));
        }

        $result = [];
        foreach ($locales as $locale) {
            $values = $decoded[$locale] ?? null;
            $title = \trim((string) ($values['title'] ?? ''));
            $description = \trim((string) ($values['description'] ?? ''));
            if ('' === $title || '' === $description) {
                throw new \RuntimeException(\sprintf('AI reply is missing title/description for locale "%s".', $locale));
            }
            $result[$locale] = ['title' => $title, 'description' => $description];
        }

        return $result;
    }

    /**
     * @param string[] $locales
     */
    private function systemPrompt(array $locales): string
    {
        $localeList = \implode(', ', \array_map(static fn (string $locale): string => '"' . $locale . '"', $locales));

        return 'You write image metadata for a website media library. Look at the image and reply with '
            . 'ONLY a JSON object with one key per locale (' . $localeList . '). Each locale maps to an object '
            . 'with "title" and "description", both written in that locale\'s language. '
            . '"description" is used as the alt text for accessibility: one factual sentence describing what '
            . 'the image shows, at most 125 characters, no phrases like "image of" or "photo of". '
            . '"title" is a short human-readable name for the file, at most 60 characters, no file extension. '
            . 'Do not add any text outside the JSON.';
    }

    /**
     * @param array<string, array{title: string, description: string}> $existing
     */
    private function userPrompt(string $fileName, array $existing): string
    {
        $prompt = 'Filename: ' . $fileName;

        foreach ($existing as $locale => $values) {
            $parts = \array_filter([
                '' !== \trim($values['title'] ?? '') ? 'title: ' . $values['title'] : null,
                '' !== \trim($values['description'] ?? '') ? 'description: ' . $values['description'] : null,
            ]);
            if ([] !== $parts) {
                $prompt .= \sprintf("\nExisting %s meta (may be a hint, may be wrong): %s", $locale, \implode(', ', $parts));
            }
        }

        return $prompt;
    }
}
