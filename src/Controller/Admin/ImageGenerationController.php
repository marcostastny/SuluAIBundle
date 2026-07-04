<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\ImageGeneration\AiCreatedCollection;
use Marcostastny\SuluAIBundle\Service\ImageGeneration\GeneratedImageSaver;
use Marcostastny\SuluAIBundle\Service\ImageGeneration\ImagePromptBuilder;
use Marcostastny\SuluAIBundle\Service\ImageGeneration\OpenAiImageGenerator;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class ImageGenerationController
{
    private const MAX_COUNT = 4;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImagePromptBuilder $promptBuilder,
        private OpenAiImageGenerator $generator,
        private AiCreatedCollection $aiCreatedCollection,
        private GeneratedImageSaver $imageSaver,
        private SecurityCheckerInterface $securityChecker,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function postAction(Request $request): Response
    {
        $this->securityChecker->checkPermission(AiSetting::SECURITY_CONTEXT_IMAGE_GENERATION, PermissionTypes::VIEW);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $data = [];
        }

        $modelId = (string) ($data['modelId'] ?? '');
        $prompt = \trim((string) ($data['prompt'] ?? ''));
        if ('' === $modelId || '' === $prompt) {
            return new JsonResponse(['message' => 'A model and a prompt are required.'], 400);
        }

        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([]);
        if (!$setting || !$setting->isEnabled() || !$setting->getApiUrl() || !$setting->getApiKey()) {
            return new JsonResponse(['message' => 'AI is not configured or not enabled.'], 400);
        }

        $model = null;
        foreach ($setting->getImageModels() as $candidate) {
            if (($candidate['modelId'] ?? null) === $modelId) {
                $model = $candidate;
                break;
            }
        }
        if (null === $model) {
            return new JsonResponse(['message' => 'Unknown image model.'], 400);
        }

        $references = $this->normaliseReferences(\is_array($data['references'] ?? null) ? $data['references'] : []);
        if ([] !== $references && !($model['supportsReference'] ?? false)) {
            return new JsonResponse(['message' => 'This model does not support reference images.'], 400);
        }

        $locale = (string) ($data['locale'] ?? 'en');
        $count = (int) ($data['count'] ?? 1);
        $count = \max(1, \min(self::MAX_COUNT, $count));

        $finalPrompt = $this->promptBuilder->buildPrompt(
            $prompt,
            isset($data['style']) ? (string) $data['style'] : null,
            isset($data['purpose']) ? (string) $data['purpose'] : null,
            $setting->getImageStylePrompt()
        );
        $size = $this->promptBuilder->buildSize(
            isset($data['format']) ? (string) $data['format'] : null
        );
        $quality = $this->promptBuilder->buildQuality(
            isset($data['resolution']) ? (string) $data['resolution'] : null
        );

        try {
            $rawImages = $this->generator->generate(
                (string) $setting->getApiUrl(),
                (string) $setting->getApiKey(),
                $modelId,
                $finalPrompt,
                $size,
                $count,
                $references,
                $quality
            );

            $userId = $this->userId();
            $collectionId = $this->aiCreatedCollection->ensure($locale, $userId);

            $images = [];
            foreach ($rawImages as $rawImage) {
                $images[] = $this->imageSaver->save($rawImage, $collectionId, $prompt, $locale, $userId);
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'Image generation failed: ' . $e->getMessage()], 502);
        }

        return new JsonResponse(['images' => $images]);
    }

    /**
     * @param array<int, mixed> $references
     *
     * @return array<int, array{filename: string, contentType: string, data: string}>
     */
    private function normaliseReferences(array $references): array
    {
        $result = [];
        foreach ($references as $reference) {
            if (!\is_array($reference) || empty($reference['data'])) {
                continue;
            }
            $decoded = \base64_decode((string) $reference['data'], true);
            if (false === $decoded) {
                continue;
            }
            $result[] = [
                'filename' => (string) ($reference['filename'] ?? 'reference.png'),
                'contentType' => (string) ($reference['contentType'] ?? 'image/png'),
                'data' => $decoded,
            ];
        }

        return $result;
    }

    private function userId(): ?int
    {
        $token = $this->tokenStorage?->getToken();
        $user = $token?->getUser();

        return (\is_object($user) && \method_exists($user, 'getId')) ? (int) $user->getId() : null;
    }
}
