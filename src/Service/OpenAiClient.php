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
     * @param array<string, mixed> $options extra HttpClient options (e.g. timeout/max_duration
     *                                      for slow endpoints such as image generation)
     *
     * @return array<string, mixed>
     */
    public function postJson(string $apiUrl, string $apiKey, string $path, array $json, array $options = []): array
    {
        $response = $this->httpClient->request('POST', $this->endpoint($apiUrl, $path), $options + [
            'auth_bearer' => $apiKey,
            'json' => $json,
        ]);

        return $this->decode($response);
    }

    /**
     * Streams a chat completion ('stream' => true) and reassembles it into the
     * non-streaming response shape while forwarding content fragments.
     *
     * @param array<string, mixed> $json
     * @param callable(string): void $onDelta called for each content fragment
     * @param array<string, mixed> $options extra HttpClient options
     *
     * @return array{choices: array{0: array{message: array<string, mixed>}}}
     */
    public function postJsonStreamed(string $apiUrl, string $apiKey, string $path, array $json, callable $onDelta, array $options = []): array
    {
        $response = $this->httpClient->request('POST', $this->endpoint($apiUrl, $path), $options + [
            'auth_bearer' => $apiKey,
            'json' => ['stream' => true] + $json,
        ]);

        if ($response->getStatusCode() >= 400) {
            return $this->decode($response); // throws with the API's error message
        }

        $role = 'assistant';
        $content = '';
        $toolCalls = [];
        $buffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();

            while (false !== ($newline = \strpos($buffer, "\n"))) {
                $line = \rtrim(\substr($buffer, 0, $newline), "\r");
                $buffer = \substr($buffer, $newline + 1);

                if (!\str_starts_with($line, 'data:')) {
                    continue;
                }
                $payload = \trim(\substr($line, 5));
                if ('' === $payload || '[DONE]' === $payload) {
                    continue;
                }
                $data = \json_decode($payload, true);
                $delta = \is_array($data) ? ($data['choices'][0]['delta'] ?? null) : null;
                if (!\is_array($delta)) {
                    continue;
                }

                if (\is_string($delta['role'] ?? null) && '' !== $delta['role']) {
                    $role = $delta['role'];
                }
                if (\is_string($delta['content'] ?? null) && '' !== $delta['content']) {
                    $content .= $delta['content'];
                    $onDelta($delta['content']);
                }
                foreach (\is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [] as $toolCallDelta) {
                    if (!\is_array($toolCallDelta)) {
                        continue;
                    }
                    $index = (int) ($toolCallDelta['index'] ?? 0);
                    if (!isset($toolCalls[$index])) {
                        $toolCalls[$index] = ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                    }
                    if (\is_string($toolCallDelta['id'] ?? null) && '' !== $toolCallDelta['id']) {
                        $toolCalls[$index]['id'] = $toolCallDelta['id'];
                    }
                    $function = \is_array($toolCallDelta['function'] ?? null) ? $toolCallDelta['function'] : [];
                    if (\is_string($function['name'] ?? null) && '' !== $function['name']) {
                        $toolCalls[$index]['function']['name'] = $function['name'];
                    }
                    if (\is_string($function['arguments'] ?? null)) {
                        $toolCalls[$index]['function']['arguments'] .= $function['arguments'];
                    }
                }
            }
        }

        $message = ['role' => $role, 'content' => '' !== $content ? $content : null];
        if ([] !== $toolCalls) {
            \ksort($toolCalls);
            $message['tool_calls'] = \array_values($toolCalls);
        }

        return ['choices' => [['message' => $message]]];
    }

    /**
     * @param array<string, mixed> $options extra HttpClient options (e.g. timeout/max_duration
     *                                      for slow endpoints such as image generation)
     *
     * @return array<string, mixed>
     */
    public function postMultipart(string $apiUrl, string $apiKey, string $path, FormDataPart $formData, array $options = []): array
    {
        $response = $this->httpClient->request('POST', $this->endpoint($apiUrl, $path), $options + [
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
