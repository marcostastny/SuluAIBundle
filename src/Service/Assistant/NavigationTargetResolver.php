<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

/**
 * Translates a document from the SEAL "admin" search index into the admin
 * view + route attributes needed to open its edit form, using the same
 * sulu_search.admin.resources config that Sulu's own search UI uses.
 */
class NavigationTargetResolver
{
    /**
     * @param array<string, array<string, mixed>> $adminResources the sulu_search.admin_resources parameter
     */
    public function __construct(
        private array $adminResources,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array{view: string, attributes: array<string, mixed>}|null
     */
    public function resolve(array $document): ?array
    {
        $resourceKey = (string) ($document['resourceKey'] ?? '');
        $route = $this->adminResources[$resourceKey]['route'] ?? null;
        if (!\is_array($route) || !isset($route['name'])) {
            return null;
        }

        $view = (string) $route['name'];
        $resultToRouteName = \is_array($route['resultToRouteName'] ?? null) ? $route['resultToRouteName'] : [];
        foreach ($resultToRouteName as $documentPath => $placeholder) {
            $value = $this->extract($document, (string) $documentPath);
            if (null === $value) {
                // A required piece of the view name is missing — the target
                // would open a broken route, so drop it rather than offer it.
                return null;
            }
            $view = \str_replace('{' . $placeholder . '}', (string) $value, $view);
        }

        $attributes = [];
        $resultToRoute = \is_array($route['resultToRoute'] ?? null) ? $route['resultToRoute'] : [];
        foreach ($resultToRoute as $documentPath => $attribute) {
            $value = $this->extract($document, (string) $documentPath);
            if (null === $value) {
                return null;
            }
            $attributes[$attribute] = $value;
        }

        return ['view' => $view, 'attributes' => $attributes];
    }

    /**
     * Resolves a dot-notation path (e.g. "metadata.webspaceKey") in the document.
     *
     * @param array<string, mixed> $document
     */
    private function extract(array $document, string $path): mixed
    {
        $value = $document;
        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
