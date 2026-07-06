<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service;

/**
 * Calls an OpenAI-compatible chat-completions endpoint to produce SEO meta.
 */
class OpenAiMetaGenerator
{
    public function __construct(private OpenAiClient $client)
    {
    }

    /**
     * @return array{title: string, description: string, keywords: string}
     */
    public function generate(
        string $apiUrl,
        string $apiKey,
        string $model,
        string $title,
        string $body,
        string $locale
    ): array {
        $data = $this->client->postJson($apiUrl, $apiKey, '/chat/completions', [
            'model' => $model,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($locale)],
                ['role' => 'user', 'content' => $this->userPrompt($title, $body)],
            ],
        ]);

        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->parseReply((string) $content);
    }

    /**
     * @return array{title: string, description: string, keywords: string}
     */
    public function parseReply(string $reply): array
    {
        // Strip a leading/trailing markdown code fence (```json ... ```), which
        // some OpenAI-compatible models add despite response_format.
        $stripped = \trim((string) \preg_replace('/```[a-zA-Z]*\n?|```/', '', $reply));

        $decoded = \json_decode($stripped, true);
        if (!\is_array($decoded)) {
            // Fall back to the outermost {...} span for replies wrapped in prose.
            $start = \strpos($stripped, '{');
            $end = \strrpos($stripped, '}');
            if (false === $start || false === $end || $end < $start) {
                throw new \RuntimeException(\sprintf(
                    'AI reply did not contain a JSON object. Reply was: %s',
                    '' === \trim($reply) ? '(empty)' : \mb_substr($reply, 0, 200)
                ));
            }
            $decoded = \json_decode(\substr($stripped, $start, $end - $start + 1), true);
        }
        if (!\is_array($decoded)) {
            throw new \RuntimeException('AI reply was not valid JSON.');
        }

        return [
            'title' => \trim((string) ($decoded['title'] ?? '')),
            'description' => \trim((string) ($decoded['description'] ?? '')),
            'keywords' => \trim((string) ($decoded['keywords'] ?? '')),
        ];
    }

    private function systemPrompt(string $locale): string
    {
        return \sprintf(
            'You are an SEO assistant. Write meta information for a web page in the locale "%s". '
            . 'Reply with ONLY a JSON object with the keys "title", "description" and "keywords". '
            . 'The title must be at most 55 characters. The description must be at most 160 characters. '
            . '"keywords" must be at most 5 comma-separated keywords. Do not add any text outside the JSON.',
            $locale
        );
    }

    private function userPrompt(string $title, string $body): string
    {
        return \sprintf("Page title: %s\n\nPage content:\n%s", $title, $body);
    }
}
