<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\LlmsTxt;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\SecurityBundle\AccessControl\AccessControlQueryEnhancer;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Published pages of a webspace/locale in tree order, with the same visibility
 * rules as Sulu's page sitemap (live, not hidden from the sitemap, no link
 * pages, website access control) plus noindex pages left out.
 */
class LlmsTxtPageProvider
{
    /**
     * @param array<string, int>|null $permissions
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WebspaceManagerInterface $webspaceManager,
        private readonly AccessControlQueryEnhancer $accessControlQueryEnhancer,
        private readonly ?Security $security = null,
        private readonly ?array $permissions = null,
    ) {
    }

    /**
     * @return array<int, array{title: string, slug: string, depth: int, description: string}>
     */
    public function getPages(string $webspaceKey, string $locale): array
    {
        $queryBuilder = $this->entityManager->getRepository(PageInterface::class)->createQueryBuilder('page')
            ->join('page.dimensionContents', 'dimensionContent', 'WITH', '
                dimensionContent.locale = :locale
                AND dimensionContent.stage = :stage
                AND dimensionContent.version = :version
                AND dimensionContent.seoHideInSitemap = false
                AND dimensionContent.seoNoIndex = false
                AND dimensionContent.linkProvider IS NULL
            ')
            ->join('dimensionContent.route', 'route')
            ->select('dimensionContent.title', 'dimensionContent.seoData', 'dimensionContent.excerptData', 'route.slug', 'page.depth')
            ->where('page.webspaceKey = :webspaceKey')
            ->orderBy('page.lft', 'ASC')
            ->setParameter('webspaceKey', $webspaceKey)
            ->setParameter('locale', $locale)
            ->setParameter('stage', DimensionContentInterface::STAGE_LIVE)
            ->setParameter('version', DimensionContentInterface::CURRENT_VERSION);

        $webspace = $this->webspaceManager->findWebspaceByKey($webspaceKey);
        if ($webspace && $webspace->hasWebsiteSecurity() && $this->permissions) {
            /** @var UserInterface|null $user */
            $user = $this->security?->getUser();
            $this->accessControlQueryEnhancer->enhance(
                $queryBuilder,
                $user,
                $this->permissions[PermissionTypes::VIEW],
                Page::class,
                'page',
                'uuid'
            );
        }

        $pages = [];
        foreach ($queryBuilder->getQuery()->getArrayResult() as $row) {
            $pages[] = [
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'depth' => (int) $row['depth'],
                'description' => (string) (($row['seoData']['description'] ?? '') ?: ($row['excerptData']['description'] ?? '')),
            ];
        }

        return $pages;
    }
}
