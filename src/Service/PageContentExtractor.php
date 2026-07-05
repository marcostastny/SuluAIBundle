<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service;

use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

/**
 * Loads a saved page and returns its title plus a flattened plain-text body.
 */
class PageContentExtractor
{
    public function __construct(
        private PageRepositoryInterface $pageRepository,
        private ContentManagerInterface $contentManager,
        private ContentTextFlattener $flattener
    ) {
    }

    /**
     * @return array{title: string, text: string, webspace: string}
     */
    public function extract(string $id, string $locale): array
    {
        $dimensionAttributes = [
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ];

        $page = $this->pageRepository->getOneBy(
            \array_merge(['uuid' => $id, 'loadGhost' => true], $dimensionAttributes),
            [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
        );

        $dimensionContent = $this->contentManager->resolve($page, $dimensionAttributes);
        $normalized = $this->contentManager->normalize($dimensionContent);

        return [
            'title' => (string) ($normalized['title'] ?? ''),
            'text' => $this->flattener->flatten($normalized),
            'webspace' => (string) ($normalized['webspaceKey'] ?? ''),
        ];
    }
}
