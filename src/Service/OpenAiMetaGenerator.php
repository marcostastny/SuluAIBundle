<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls an OpenAI-compatible chat-completions endpoint to produce SEO meta.
 */
class OpenAiMetaGenerator
{
    public function __construct(private HttpClientInterface $httpClient)
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
        $response = $this->httpClient->request(
            'POST',
            \rtrim($apiUrl, '/') . '/chat/completions',
            [
                'auth_bearer' => $apiKey,
                'json' => [
                    'model' => $model,
                    'temperature' => 0.4,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt($locale)],
                        ['role' => 'user', 'content' => $this->userPrompt($title, $body)],
                    ],
                ],
            ]
        );

        $data = $response->toArray(false);
        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->parseReply((string) $content);
    }

    /**
     * @return array{title: string, description: string, keywords: string}
     */
    public function parseReply(string $reply): array
    {
        $start = \strpos($reply, '{');
        $end = \strrpos($reply, '}');
        if (false === $start || false === $end || $end < $start) {
            throw new \RuntimeException('AI reply did not contain a JSON object.');
        }

        $decoded = \json_decode(\substr($reply, $start, $end - $start + 1), true);
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
