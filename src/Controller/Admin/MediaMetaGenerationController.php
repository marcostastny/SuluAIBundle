<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaFinder;
use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaGenerator;
use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaWriter;
use Marcostastny\SuluAIBundle\Service\MediaMeta\PreviewNotSupportedException;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Component\Localization\Manager\LocalizationManagerInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Bulk media meta generation. One batch request processes at most
 * BATCH_LIMIT images (the admin UI loops); each image gets ONE vision call
 * covering every locale. Failures are collected per image, never aborting
 * the batch. Missing-mode is resumable by construction: written images drop
 * out of the finder, and the client sends ids that errored as excludeIds so
 * they are not re-selected forever.
 */
class MediaMetaGenerationController
{
    private const BATCH_LIMIT = 5;
    private const MAX_EXCLUDE_IDS = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MediaMetaFinder $finder,
        private MediaMetaGenerator $generator,
        private MediaMetaWriter $writer,
        private SecurityCheckerInterface $securityChecker,
        private TokenStorageInterface $tokenStorage,
        private LocalizationManagerInterface $localizationManager,
    ) {
    }

    public function getMissingCountAction(): Response
    {
        $this->checkPermissions();

        if (null === $this->setting()) {
            return new JsonResponse(['message' => 'AI is not configured or not enabled.'], 400);
        }

        return new JsonResponse(['count' => $this->finder->count($this->locales())]);
    }

    public function postBatchAction(Request $request): Response
    {
        $this->checkPermissions();

        $setting = $this->setting();
        if (null === $setting) {
            return new JsonResponse(['message' => 'AI is not configured or not enabled.'], 400);
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return new JsonResponse(['message' => 'No authenticated Sulu user.'], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Invalid JSON body.'], 400);
        }

        $mode = (string) ($data['mode'] ?? '');
        if (!\in_array($mode, ['missing', 'selected'], true)) {
            return new JsonResponse(['message' => 'mode must be "missing" or "selected".'], 400);
        }

        $locales = $this->locales();
        $override = 'selected' === $mode;

        if ($override) {
            $ids = $this->intList($data['ids'] ?? []);
            if ([] === $ids) {
                return new JsonResponse(['message' => 'ids must be a non-empty list of media ids.'], 400);
            }
            $ids = \array_slice($ids, 0, self::BATCH_LIMIT);
            $excludeIds = [];
        } else {
            $excludeIds = \array_slice($this->intList($data['excludeIds'] ?? []), 0, self::MAX_EXCLUDE_IDS);
            $ids = $this->finder->findIds($locales, self::BATCH_LIMIT, $excludeIds);
        }

        $mediaById = [];
        if ([] !== $ids) {
            foreach ($this->entityManager->getRepository(Media::class)->findBy(['id' => $ids]) as $media) {
                $mediaById[$media->getId()] = $media;
            }
        }

        $processed = [];
        $skipped = [];
        $errors = [];

        foreach ($ids as $id) {
            $media = $mediaById[$id] ?? null;
            if (null === $media) {
                $skipped[] = ['id' => $id, 'reason' => 'not-found'];
                continue;
            }

            $fileVersion = $this->writer->latestFileVersion($media);
            if (null === $fileVersion
                || !\in_array((string) $fileVersion->getMimeType(), MediaMetaFinder::RASTER_MIME_TYPES, true)
            ) {
                $skipped[] = ['id' => $id, 'reason' => 'not-an-image'];
                continue;
            }

            try {
                $generated = $this->generator->generate(
                    (string) $setting->getApiUrl(),
                    (string) $setting->getApiKey(),
                    (string) $setting->getModel(),
                    $fileVersion,
                    $locales,
                    $this->writer->existingMeta($media, $locales)
                );
                $written = $this->writer->write($media, $generated, $override, (int) $user->getId());
                $processed[] = ['id' => $id, 'locales' => $written];
            } catch (PreviewNotSupportedException) {
                $skipped[] = ['id' => $id, 'reason' => 'no-preview'];
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        // Errored AND skipped images stay "missing" in the database - both
        // must leave the remaining count or the client would loop forever.
        $stillExcluded = [...$excludeIds, ...\array_column($errors, 'id'), ...\array_column($skipped, 'id')];
        $remaining = $override ? 0 : $this->finder->count($locales, $stillExcluded);

        return new JsonResponse([
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'remaining' => $remaining,
        ]);
    }

    private function checkPermissions(): void
    {
        $this->securityChecker->checkPermission(AiSetting::SECURITY_CONTEXT_GENERATION, PermissionTypes::VIEW);
        // The endpoints write media, so the user needs Sulu's media edit
        // permission on top of the AI meta-generation grant.
        $this->securityChecker->checkPermission(MediaAdmin::SECURITY_CONTEXT, PermissionTypes::EDIT);
    }

    private function setting(): ?AiSetting
    {
        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([], ['id' => 'ASC']);

        return null !== $setting && $setting->isConfigured() ? $setting : null;
    }

    /**
     * @return string[]
     */
    private function locales(): array
    {
        return \array_values($this->localizationManager->getLocales());
    }

    /**
     * @return int[]
     */
    private function intList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter(\array_map(
            static fn (mixed $id): int => (int) $id,
            $value
        ), static fn (int $id): bool => $id > 0));
    }
}
