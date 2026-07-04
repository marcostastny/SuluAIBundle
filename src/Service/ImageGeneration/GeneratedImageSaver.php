<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\ImageGeneration;

use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Writes one generated image (base64 or remote url) into a media collection
 * via Sulu's MediaManager and returns a light descriptor for the frontend.
 */
class GeneratedImageSaver
{
    public function __construct(
        private MediaManagerInterface $mediaManager,
        private HttpClientInterface $httpClient
    ) {
    }

    /**
     * @param array{b64: string|null, url: string|null} $payload
     *
     * @return array{id: int, thumbnailUrl: string, title: string}
     */
    public function save(array $payload, int $collectionId, string $title, string $locale, ?int $userId): array
    {
        $bytes = $this->resolveBytes($payload);

        $tmpPath = \tempnam(\sys_get_temp_dir(), 'sulu_ai_img');
        if (false === $tmpPath) {
            throw new \RuntimeException('Unable to create a temporary file for the generated image.');
        }

        try {
            \file_put_contents($tmpPath, $bytes);

            $uploadedFile = new UploadedFile($tmpPath, $this->fileName($title), 'image/png', null, true);

            $media = $this->mediaManager->save(
                $uploadedFile,
                [
                    'collection' => $collectionId,
                    'locale' => $locale,
                    'title' => $title,
                ],
                $userId
            );

            $thumbnails = $media->getThumbnails() ?: [];

            return [
                'id' => (int) $media->getId(),
                'thumbnailUrl' => (string) (\reset($thumbnails) ?: $media->getUrl()),
                'title' => (string) $media->getTitle(),
            ];
        } finally {
            // Sulu's storage copies the file's contents rather than moving it,
            // so the temp file must be removed here or it leaks into the system
            // temp dir on every generated image.
            if (\is_file($tmpPath)) {
                @\unlink($tmpPath);
            }
        }
    }

    /**
     * @param array{b64: string|null, url: string|null} $payload
     */
    private function resolveBytes(array $payload): string
    {
        if (!empty($payload['b64'])) {
            $decoded = \base64_decode((string) $payload['b64'], true);
            if (false === $decoded) {
                throw new \RuntimeException('Generated image was not valid base64.');
            }

            return $decoded;
        }

        if (!empty($payload['url'])) {
            return $this->httpClient->request('GET', (string) $payload['url'])->getContent();
        }

        throw new \RuntimeException('Generated image payload had neither base64 data nor a url.');
    }

    private function fileName(string $title): string
    {
        $slug = \preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'ai-image';
        $slug = \trim(\strtolower($slug), '-');
        $slug = '' === $slug ? 'ai-image' : \substr($slug, 0, 40);

        return $slug . '.png';
    }
}
