<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Website;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\LlmsTxt\LlmsTxtPageProvider;
use Marcostastny\SuluAIBundle\Service\LlmsTxt\LlmsTxtRenderer;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves /llms.txt (webspace default locale) and /{locale}/llms.txt.
 */
class LlmsTxtController
{
    public const CACHE_LIFETIME = 600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestAnalyzerInterface $requestAnalyzer,
        private readonly WebspaceManagerInterface $webspaceManager,
        private readonly LlmsTxtPageProvider $pageProvider,
        private readonly LlmsTxtRenderer $renderer,
        private readonly string $environment,
    ) {
    }

    public function indexAction(Request $request): Response
    {
        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([], ['id' => 'ASC']);
        $webspace = $this->requestAnalyzer->getWebspace();
        if (!$setting instanceof AiSetting || !$setting->isLlmsTxtEnabled() || !$webspace) {
            throw new NotFoundHttpException();
        }

        $locale = $this->requestAnalyzer->getCurrentLocalization()?->getLocale()
            ?? $webspace->getDefaultLocalization()->getLocale();
        $host = $request->getHost();
        $scheme = $request->getScheme();
        $url = fn (string $path, string $pathLocale): ?string => $this->webspaceManager->findUrlByResourceLocator(
            $path,
            $this->environment,
            $pathLocale,
            $webspace->getKey(),
            $host,
            $scheme
        );

        $homeDescription = '';
        $pages = [];
        foreach ($this->pageProvider->getPages($webspace->getKey(), $locale) as $page) {
            if (0 === $page['depth']) {
                $homeDescription = $page['description'];

                continue;
            }
            $pageUrl = $url($page['slug'], $locale);
            if ($pageUrl) {
                $pages[] = ['title' => $page['title'], 'url' => $pageUrl, 'description' => $page['description']];
            }
        }

        $alternates = [];
        foreach ($webspace->getAllLocalizations() as $localization) {
            $alternateLocale = $localization->getLocale();
            $alternateUrl = $alternateLocale !== $locale ? $url('/llms.txt', $alternateLocale) : null;
            if ($alternateUrl) {
                $alternates[$alternateLocale] = $alternateUrl;
            }
        }

        $text = $setting->getLlmsTxtText($locale);
        $response = new Response($this->renderer->render(
            $webspace->getName(),
            ($text['summary'] ?? null) ?: $homeDescription,
            $text['notes'] ?? null,
            $pages,
            $alternates,
        ));
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        // ponytail: time-based expiry, no invalidation on publish; tag the response if 10 min is too slow.
        $response->setPublic();
        $response->setMaxAge(self::CACHE_LIFETIME);
        $response->setSharedMaxAge(self::CACHE_LIFETIME);

        return $response;
    }
}
