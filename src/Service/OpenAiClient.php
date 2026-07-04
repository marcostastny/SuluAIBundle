<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service;

use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin wrapper over an OpenAI-compatible HTTP API: joins the base URL, sets
 * bearer auth, and turns a >= 400 response into a RuntimeException carrying the
 * API's error message. Shared by the meta, assistant and image services so the
 * request and error handling live in one place.
 */
class OpenAiClient
{
    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    public function postJson(string $apiUrl, string $apiKey, string $path, array $json): array
    {
        $response = $this->httpClient->request('POST', $this->endpoint($apiUrl, $path), [
            'auth_bearer' => $apiKey,
            'json' => $json,
        ]);

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function postMultipart(string $apiUrl, string $apiKey, string $path, FormDataPart $formData): array
    {
        $response = $this->httpClient->request('POST', $this->endpoint($apiUrl, $path), [
            'auth_bearer' => $apiKey,
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToIterable(),
        ]);

        return $this->decode($response);
    }

    private function endpoint(string $apiUrl, string $path): string
    {
        return \rtrim($apiUrl, '/') . '/' . \ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode >= 400) {
            $message = $data['error']['message'] ?? \json_encode($data);

            throw new \RuntimeException(\sprintf('API returned status %d: %s', $statusCode, $message));
        }

        return $data;
    }
}
