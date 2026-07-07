<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\Creation;

use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Route\Application\ResourceLocator\ResourceLocatorGeneratorInterface;
use Sulu\Route\Application\ResourceLocator\ResourceLocatorRequest;

/**
 * Validates a propose_page_creation tool call and assembles the createPage
 * action for the approval card. The parent must come from search_content in
 * the same turn (or be "homepage"), so the model can never invent parents;
 * the URL is composed server-side by Sulu's resource-locator generator, which
 * also handles uniqueness.
 */
class PageCreationValidator
{
    public const HOMEPAGE_PARENT_ID = 'homepage';

    public function __construct(
        private MetadataProviderInterface $formMetadataProvider,
        private NavigationTargetCollector $targetCollector,
        private ResourceLocatorGeneratorInterface $resourceLocatorGenerator,
        private PageCreationGate $creationGate,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments raw tool-call arguments
     *
     * @return array{action: array<string, mixed>}|array{errors: list<string>}
     */
    public function validate(array $arguments, ?string $contextWebspace): array
    {
        $errors = [];
        $title = \trim((string) ($arguments['title'] ?? ''));
        $template = \trim((string) ($arguments['template'] ?? ''));
        $locale = \trim((string) ($arguments['locale'] ?? ''));
        $parentId = \trim((string) ($arguments['parent_id'] ?? ''));
        $parentLocale = \trim((string) ($arguments['parent_locale'] ?? ''));

        if ('' === $title) {
            $errors[] = 'title must not be empty.';
        }
        if ('' === $locale) {
            $errors[] = 'locale must not be empty.';
        }
        if ('' === $template) {
            $errors[] = 'template must not be empty.';
        }
        if ('' === $parentId) {
            $errors[] = 'parent_id must not be empty; use "homepage" for a top-level page.';
        }

        $templateTitle = $template;
        if ('' !== $locale && '' !== $template) {
            $forms = [];
            try {
                $typedFormMetadata = $this->formMetadataProvider->getMetadata('page', $locale, []);
                if ($typedFormMetadata instanceof TypedFormMetadata) {
                    $forms = $typedFormMetadata->getForms();
                }
            } catch (\Throwable) {
                // Treated as unknown template below.
            }
            $formMetadata = $forms[$template] ?? null;
            if (null === $formMetadata) {
                $errors[] = \sprintf(
                    'template "%s" not found. Available templates: %s',
                    $template,
                    \implode(', ', \array_keys($forms))
                );
            } else {
                $templateTitle = $formMetadata->getTitle($locale) ?: $template;
            }
        }

        $parentTitle = '';
        $webspace = null !== $contextWebspace && '' !== $contextWebspace ? $contextWebspace : null;
        if ('' !== $parentId && self::HOMEPAGE_PARENT_ID !== $parentId) {
            $target = $this->targetCollector->get('pages', $parentId, '' !== $parentLocale ? $parentLocale : $locale);
            if (null === $target) {
                $errors[] = \sprintf(
                    'parent %s (locale %s) was not returned by search_content in this turn. Call search_content to find the parent page first, or use "homepage".',
                    $parentId,
                    '' !== $parentLocale ? $parentLocale : $locale
                );
            } else {
                $parentTitle = $target['title'];
                $targetWebspace = $target['attributes']['webspace'] ?? null;
                if (\is_string($targetWebspace) && '' !== $targetWebspace) {
                    $webspace = $targetWebspace;
                }
            }
        }

        if (null === $webspace) {
            $webspace = $this->creationGate->soleAllowedWebspaceKey();
        }
        if (null === $webspace) {
            $errors[] = 'The target webspace could not be determined. Find the parent page via search_content and pass its id and locale as parent_id/parent_locale.';
        }

        if ([] !== $errors) {
            return ['errors' => $errors];
        }

        try {
            $url = $this->resourceLocatorGenerator->generate(new ResourceLocatorRequest(
                ['title' => $title],
                $locale,
                $webspace,
                'pages',
                null,
                self::HOMEPAGE_PARENT_ID === $parentId ? null : $parentId,
                'pages',
                null,
                false,
            ));
        } catch (\Throwable $e) {
            return ['errors' => [\sprintf('Could not generate a URL for this page: %s', $e->getMessage())]];
        }

        return ['action' => [
            'type' => 'createPage',
            'message' => (string) ($arguments['message'] ?? ''),
            'title' => $title,
            'template' => $template,
            'templateTitle' => $templateTitle,
            'parentId' => $parentId,
            'parentTitle' => $parentTitle,
            'webspace' => $webspace,
            'locale' => $locale,
            'url' => $url,
            'resume' => (bool) ($arguments['resume'] ?? false),
        ]];
    }
}
