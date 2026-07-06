<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetResolver;
use PHPUnit\Framework\TestCase;

class NavigationTargetResolverTest extends TestCase
{
    private function resolver(): NavigationTargetResolver
    {
        return new NavigationTargetResolver([
            'pages' => [
                'route' => [
                    'name' => 'sulu_page.page_edit_form',
                    'resultToRoute' => [
                        'resourceId' => 'id',
                        'locale' => 'locale',
                        'metadata.webspaceKey' => 'webspace',
                    ],
                ],
            ],
            'articles' => [
                'route' => [
                    'name' => 'sulu_article.edit_tabs_{group}',
                    'resultToRouteName' => ['metadata.group' => 'group'],
                    'resultToRoute' => [
                        'resourceId' => 'id',
                        'locale' => 'locale',
                    ],
                ],
            ],
        ]);
    }

    public function testResolvesPageDocument(): void
    {
        $result = $this->resolver()->resolve([
            'resourceKey' => 'pages',
            'resourceId' => '42',
            'locale' => 'de',
            'metadata' => ['webspaceKey' => 'kulm'],
        ]);

        $this->assertSame(
            ['view' => 'sulu_page.page_edit_form', 'attributes' => ['id' => '42', 'locale' => 'de', 'webspace' => 'kulm']],
            $result
        );
    }

    public function testResolvesArticleRouteNamePlaceholder(): void
    {
        $result = $this->resolver()->resolve([
            'resourceKey' => 'articles',
            'resourceId' => '7',
            'locale' => 'en',
            'metadata' => ['group' => 'blog'],
        ]);

        $this->assertSame(
            ['view' => 'sulu_article.edit_tabs_blog', 'attributes' => ['id' => '7', 'locale' => 'en']],
            $result
        );
    }

    public function testUnknownResourceKeyReturnsNull(): void
    {
        $this->assertNull($this->resolver()->resolve(['resourceKey' => 'contacts', 'resourceId' => '1']));
    }

    public function testMissingDocumentPathReturnsNullSoNoBrokenTargetIsOffered(): void
    {
        // The page route needs a webspace; without it the target would open a
        // broken route, so the resolver drops it rather than emit a null attr.
        $result = $this->resolver()->resolve([
            'resourceKey' => 'pages',
            'resourceId' => '42',
            'locale' => 'de',
        ]);

        $this->assertNull($result);
    }
}
