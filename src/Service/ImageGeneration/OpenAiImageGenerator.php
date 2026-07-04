<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\ImageGeneration;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls an OpenAI-compatible images endpoint (generations, or edits when
 * reference images are supplied).
 */
class OpenAiImageGenerator
{
    public function __construct(private HttpClientInterface $httpClient)
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
        array $references
    ): array {
        $base = \rtrim($apiUrl, '/');

        if ([] === $references) {
            $response = $this->httpClient->request('POST', $base . '/images/generations', [
                'auth_bearer' => $apiKey,
                'json' => [
                    'model' => $modelId,
                    'prompt' => $prompt,
                    'n' => $count,
                    'size' => $size,
                    'response_format' => 'b64_json',
                ],
            ]);
        } else {
            $fields = [
                'model' => $modelId,
                'prompt' => $prompt,
                'n' => (string) $count,
                'size' => $size,
            ];
            foreach ($references as $reference) {
                $fields['image[]'][] = new DataPart(
                    $reference['data'],
                    $reference['filename'],
                    $reference['contentType']
                );
            }
            $formData = new FormDataPart($fields);
            $response = $this->httpClient->request('POST', $base . '/images/edits', [
                'auth_bearer' => $apiKey,
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);
        }

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode >= 400) {
            $message = $data['error']['message'] ?? \json_encode($data);

            throw new \RuntimeException(\sprintf('Image API returned status %d: %s', $statusCode, $message));
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
