<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\ImageGeneration;

use Marcostastny\SuluAIBundle\Service\OpenAiClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * Calls an OpenAI-compatible images endpoint (generations, or edits when
 * reference images are supplied).
 */
class OpenAiImageGenerator
{
    /**
     * gpt-image-* models stream no bytes until the image is fully rendered,
     * which regularly exceeds PHP's default_socket_timeout (60s) — the idle
     * timeout must cover the whole render time.
     */
    private const REQUEST_OPTIONS = [
        'timeout' => 300,
        'max_duration' => 320,
    ];

    public function __construct(private OpenAiClient $client)
    {
    }

    /**
     * @param array<int, array{filename: string, contentType: string, data: string}> $references
     *
     * @return array<int, array{b64: string|null, url: string|null}>
     */
    public function generate(
        string $apiUrl,
        string $apiKey,
        string $modelId,
        string $prompt,
        string $size,
        int $count,
        array $references,
        ?string $quality = null
    ): array {
        if ([] === $references) {
            $json = [
                'model' => $modelId,
                'prompt' => $prompt,
                'n' => $count,
                'size' => $size,
            ];
            if (null !== $quality) {
                $json['quality'] = $quality;
            }

            // No response_format: the gpt-image-* models reject it and return
            // b64_json by default; DALL·E returns a url, which the saver fetches.
            $data = $this->client->postJson($apiUrl, $apiKey, '/images/generations', $json, self::REQUEST_OPTIONS);
        } else {
            $fields = [
                'model' => $modelId,
                'prompt' => $prompt,
                'n' => (string) $count,
                'size' => $size,
            ];
            if (null !== $quality) {
                $fields['quality'] = $quality;
            }
            foreach ($references as $reference) {
                // Integer-keyed single-entry wrappers make FormDataPart emit
                // repeated "image[]" parts. Assigning to $fields['image[]'][]
                // instead would name them "image[][0]", "image[][1]", which the
                // images/edits endpoint rejects.
                $fields[] = ['image[]' => new DataPart(
                    $reference['data'],
                    $reference['filename'],
                    $reference['contentType']
                )];
            }

            $data = $this->client->postMultipart($apiUrl, $apiKey, '/images/edits', new FormDataPart($fields), self::REQUEST_OPTIONS);
        }

        $images = [];
        foreach (($data['data'] ?? []) as $item) {
            $images[] = [
                'b64' => isset($item['b64_json']) ? (string) $item['b64_json'] : null,
                'url' => isset($item['url']) ? (string) $item['url'] : null,
            ];
        }

        return $images;
    }
}
