<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\Tool;

use Marcostastny\SuluAIBundle\Service\Assistant\AssistantToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Route\Domain\Model\Route;
use Sulu\Route\Domain\Repository\RouteRepositoryInterface;

/**
 * Resolves a website URL (or path) to the exact page via Sulu's route table,
 * so pasted links deterministically find the right page instead of relying on
 * a title search over the slug words. Resolved targets are registered in the
 * collector like search results; opening one still goes through Sulu's own
 * view permissions.
 */
class ResolveUrlTool implements AssistantToolInterface
{
    public function __construct(
        private RouteRepositoryInterface $routeRepository,
        private NavigationTargetCollector $targetCollector,
        private WebspaceManagerInterface $webspaceManager,
    ) {
    }

    public function getName(): string
    {
        return 'resolve_url';
    }

    public function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'resolve_url',
                'description' => 'Resolve a website URL or path (e.g. pasted by the user) to the exact CMS page via the route table. Returns matching pages with type, id, locale and title, usable with propose_navigation and propose_page_creation like search_content results.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'The full URL or the path, e.g. "https://example.com/angebote/wellness" or "/angebote/wellness".',
                        ],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $url = \trim((string) ($arguments['url'] ?? ''));
        if ('' === $url) {
            return ['error' => 'url must not be empty.'];
        }

        $path = $this->extractPath($url);

        $routes = $this->pageRoutes($this->routeRepository->findBy(['slug' => $path, 'resourceKey' => 'pages']));

        // Public URLs may carry a locale prefix (/en/...) that route slugs
        // do not contain — retry without it, pinned to that locale.
        if ([] === $routes && 1 === \preg_match('#^/([a-z]{2})(/.*)?$#', $path, $matches)) {
            $localePath = '' !== ($matches[2] ?? '') ? $matches[2] : '/';
            $routes = $this->pageRoutes($this->routeRepository->findBy([
                'slug' => $localePath,
                'resourceKey' => 'pages',
                'locale' => $matches[1],
            ]));
        }

        $results = [];
        foreach ($routes as $route) {
            $webspace = $route->getWebspace() ?? $this->soleWebspaceKey();
            if (null === $webspace) {
                continue;
            }

            $target = [
                'type' => 'pages',
                'id' => $route->getResourceId(),
                'locale' => $route->getLocale(),
                'title' => $route->getSlug(),
                'view' => 'sulu_page.page_edit_form',
                'attributes' => [
                    'id' => $route->getResourceId(),
                    'locale' => $route->getLocale(),
                    'webspace' => $webspace,
                ],
            ];
            $this->targetCollector->add($target);
            $results[] = \array_diff_key($target, ['view' => true, 'attributes' => true]);
        }

        $response = ['results' => $results];
        if ([] === $results) {
            $response['note'] = 'No page found for this URL. Try search_content with words from the URL instead.';
        }

        return $response;
    }

    private function extractPath(string $url): string
    {
        $candidate = $url;
        if (!\str_starts_with($candidate, '/')) {
            // parse_url needs a scheme to split host and path reliably.
            if (!\preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
                $candidate = 'https://' . $candidate;
            }
            $candidate = (string) (\parse_url($candidate, \PHP_URL_PATH) ?? '/');
        } else {
            // Strip query string and fragment from bare paths.
            $candidate = (string) (\parse_url($candidate, \PHP_URL_PATH) ?? $candidate);
        }

        $trimmed = \trim($candidate, '/');

        return '' === $trimmed ? '/' : '/' . $trimmed;
    }

    /**
     * @param iterable<Route> $routes
     *
     * @return list<Route>
     */
    private function pageRoutes(iterable $routes): array
    {
        $pages = [];
        foreach ($routes as $route) {
            if ('pages' === $route->getResourceKey()) {
                $pages[] = $route;
            }
        }

        return $pages;
    }

    private function soleWebspaceKey(): ?string
    {
        try {
            $keys = [];
            foreach ($this->webspaceManager->getWebspaceCollection()->getWebspaces() as $webspace) {
                $keys[] = (string) $webspace->getKey();
            }

            return 1 === \count($keys) ? $keys[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
