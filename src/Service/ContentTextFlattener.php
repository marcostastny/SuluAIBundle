<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service;

/**
 * Flattens a normalized Sulu content array into a plain-text blob for prompting.
 */
class ContentTextFlattener
{
    /**
     * Keys that never contain human-readable body text and would only add noise
     * (ids, routing, structural metadata, and the SEO/excerpt extensions themselves).
     *
     * @var string[]
     */
    private const SKIP_KEYS = [
        'id', 'uuid', 'template', 'type', 'settings', 'seo', 'excerpt', 'taxonomies',
        'url', 'href', 'target', 'published', 'publishedState', 'created', 'changed',
        'author', 'authored', 'navContexts', 'shadowOn', 'shadowBaseLanguage',
        'webspaceKey', 'locale', 'originLocale', 'path', 'nodeType', 'order',
    ];

    /**
     * @param array<mixed> $content
     */
    public function flatten(array $content, int $maxLength = 6000): string
    {
        $parts = [];
        $this->collect($content, $parts);

        $text = \implode("\n", $parts);
        $text = (string) \preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) \preg_replace('/\n{2,}/', "\n", $text);
        $text = \trim($text);

        if (\mb_strlen($text) > $maxLength) {
            $text = \mb_substr($text, 0, $maxLength);
        }

        return $text;
    }

    /**
     * @param mixed    $value
     * @param string[] $parts
     */
    private function collect($value, array &$parts): void
    {
        if (\is_string($value)) {
            $clean = \trim(\strip_tags($value));
            if ('' !== $clean) {
                $parts[] = $clean;
            }

            return;
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                if (\is_string($key) && \in_array($key, self::SKIP_KEYS, true)) {
                    continue;
                }
                $this->collect($item, $parts);
            }
        }
    }
}
