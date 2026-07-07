<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\OpenAiClient;

/**
 * One small completion to title a new chat session. Failures must never
 * break the chat turn, so any problem falls back to the truncated first
 * user message.
 */
class SessionTitleGenerator
{
    private const MAX_TITLE_LENGTH = 60;
    private const FALLBACK_LENGTH = 40;

    public function __construct(private OpenAiClient $client)
    {
    }

    public function generate(AiSetting $setting, string $userMessage, string $assistantReply): string
    {
        try {
            $data = $this->client->postJson(
                (string) $setting->getApiUrl(),
                (string) $setting->getApiKey(),
                '/chat/completions',
                [
                    'model' => (string) $setting->getModel(),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Summarize the conversation as a short session title: max 6 words, in the language of the conversation, plain text without quotes.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "User: {$userMessage}\n\nAssistant: {$assistantReply}",
                        ],
                    ],
                ]
            );

            $title = \trim((string) ($data['choices'][0]['message']['content'] ?? ''), " \t\n\r\0\x0B\"'");
            if ('' !== $title) {
                return \mb_substr($title, 0, self::MAX_TITLE_LENGTH);
            }
        } catch (\Throwable) {
            // Fall through to the fallback.
        }

        return \mb_substr(\trim($userMessage), 0, self::FALLBACK_LENGTH);
    }
}
