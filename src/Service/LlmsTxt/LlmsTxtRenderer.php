<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\LlmsTxt;

/**
 * Renders an llms.txt document (https://llmstxt.org): H1 site name, optional
 * blockquote summary, optional free notes, then link-list sections.
 */
class LlmsTxtRenderer
{
    /**
     * @param array<int, array{title: string, url: string, description: string}> $pages
     * @param array<string, string> $alternates locale => llms.txt URL of that language
     */
    public function render(string $siteName, ?string $summary, ?string $notes, array $pages, array $alternates): string
    {
        $parts = ['# ' . $this->line($siteName)];

        $summary = $this->line((string) $summary);
        if ('' !== $summary) {
            $parts[] = '> ' . $summary;
        }

        $notes = \trim((string) $notes);
        if ('' !== $notes) {
            $parts[] = $notes;
        }

        if ([] !== $pages) {
            $items = \array_map(function (array $page): string {
                $item = $this->link($page['title'], $page['url']);
                $description = $this->line($page['description']);

                return '' === $description ? $item : $item . ': ' . $description;
            }, $pages);
            $parts[] = "## Pages\n\n" . \implode("\n", $items);
        }

        if ([] !== $alternates) {
            $items = [];
            foreach ($alternates as $locale => $url) {
                $items[] = $this->link((string) $locale, $url);
            }
            $parts[] = "## Other languages\n\n" . \implode("\n", $items);
        }

        return \implode("\n\n", $parts) . "\n";
    }

    private function link(string $text, string $url): string
    {
        $text = \str_replace(['[', ']'], ['\[', '\]'], $this->line($text));
        $url = \str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url);

        return '- [' . $text . '](' . $url . ')';
    }

    /** Collapses whitespace so a value cannot break out of its Markdown line. */
    private function line(string $value): string
    {
        return \trim((string) \preg_replace('/\s+/u', ' ', $value));
    }
}
