<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\OpenAiMetaGenerator;
use Marcostastny\SuluAIBundle\Service\PageContentExtractor;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Page\Domain\Exception\PageNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MetaGenerationController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageContentExtractor $pageContentExtractor,
        private OpenAiMetaGenerator $metaGenerator,
        private SecurityCheckerInterface $securityChecker
    ) {
    }

    public function postAction(Request $request): Response
    {
        $this->securityChecker->checkPermission(AiSetting::SECURITY_CONTEXT, PermissionTypes::EDIT);

        $data = $request->request->all();
        $id = (string) ($data['id'] ?? '');
        $locale = (string) ($data['locale'] ?? '');

        if ('' === $id || '-' === $id || '' === $locale) {
            return new JsonResponse(['message' => 'Missing page id or locale.'], 400);
        }

        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([]);
        if (!$setting || !$setting->isEnabled() || !$setting->getApiUrl() || !$setting->getApiKey() || !$setting->getModel()) {
            return new JsonResponse(['message' => 'AI is not configured or not enabled.'], 400);
        }

        try {
            $page = $this->pageContentExtractor->extract($id, $locale);
        } catch (PageNotFoundException) {
            return new JsonResponse(['message' => 'Page not found.'], 404);
        }

        try {
            $meta = $this->metaGenerator->generate(
                (string) $setting->getApiUrl(),
                (string) $setting->getApiKey(),
                (string) $setting->getModel(),
                $page['title'],
                $page['text'],
                $locale
            );
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'AI request failed: ' . $e->getMessage()], 502);
        }

        return new JsonResponse($meta);
    }
}
