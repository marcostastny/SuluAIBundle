<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\Tool;

use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\Tool\ResolveUrlTool;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Route\Domain\Model\Route;
use Sulu\Route\Domain\Repository\RouteRepositoryInterface;

class ResolveUrlToolTest extends TestCase
{
    private NavigationTargetCollector $collector;

    /**
     * @param array<string, list<Route>> $routesBySlug
     */
    private function createTool(array $routesBySlug, array $webspaceKeys = ['kulm']): ResolveUrlTool
    {
        $repository = $this->createMock(RouteRepositoryInterface::class);
        $repository->method('findBy')->willReturnCallback(
            static fn (array $filters): iterable => $routesBySlug[$filters['slug'] ?? ''] ?? []
        );

        $webspaces = [];
        foreach ($webspaceKeys as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }
        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn(new WebspaceCollection($webspaces));

        $this->collector = new NavigationTargetCollector();

        return new ResolveUrlTool($repository, $this->collector, $webspaceManager);
    }

    public function testResolvesFullUrlToPageTarget(): void
    {
        $route = new Route('pages', 'abc-123', 'de', '/angebote/wellness-weekend', 'kulm');
        $tool = $this->createTool(['/angebote/wellness-weekend' => [$route]]);

        $result = $tool->execute(['url' => 'https://hotelkulm.memo:33001/angebote/wellness-weekend?x=1#top']);

        $this->assertSame(
            [['type' => 'pages', 'id' => 'abc-123', 'locale' => 'de', 'title' => '/angebote/wellness-weekend']],
            $result['results']
        );
        $target = $this->collector->get('pages', 'abc-123', 'de');
        $this->assertNotNull($target);
        $this->assertSame('sulu_page.page_edit_form', $target['view']);
        $this->assertSame(
            ['id' => 'abc-123', 'locale' => 'de', 'webspace' => 'kulm'],
            $target['attributes']
        );
    }

    public function testResolvesPlainPathWithoutHost(): void
    {
        $route = new Route('pages', 'abc-123', 'de', '/angebote', 'kulm');
        $tool = $this->createTool(['/angebote' => [$route]]);

        $result = $tool->execute(['url' => '/angebote/']);

        $this->assertCount(1, $result['results']);
    }

    public function testStripsLocalePrefixAsFallback(): void
    {
        $route = new Route('pages', 'en-1', 'en', '/offers', 'kulm');
        $tool = $this->createTool(['/offers' => [$route]]);

        $result = $tool->execute(['url' => 'https://hotelkulm.memo/en/offers']);

        $this->assertSame('en-1', $result['results'][0]['id']);
        $this->assertSame('en', $result['results'][0]['locale']);
    }

    public function testIgnoresNonPageRoutes(): void
    {
        $route = new Route('articles', 'a-1', 'de', '/blog/post', 'kulm');
        $tool = $this->createTool(['/blog/post' => [$route]]);

        $result = $tool->execute(['url' => '/blog/post']);

        $this->assertSame([], $result['results']);
        $this->assertArrayHasKey('note', $result);
    }

    public function testUnknownUrlReturnsNote(): void
    {
        $tool = $this->createTool([]);

        $result = $tool->execute(['url' => '/does-not-exist']);

        $this->assertSame([], $result['results']);
        $this->assertArrayHasKey('note', $result);
    }

    public function testWebspaceFallsBackToSoleWebspace(): void
    {
        $route = new Route('pages', 'abc-123', 'de', '/angebote', null);
        $tool = $this->createTool(['/angebote' => [$route]]);

        $tool->execute(['url' => '/angebote']);

        $target = $this->collector->get('pages', 'abc-123', 'de');
        $this->assertSame('kulm', $target['attributes']['webspace']);
    }

    public function testEmptyUrlIsAnError(): void
    {
        $tool = $this->createTool([]);

        $this->assertArrayHasKey('error', $tool->execute(['url' => ' ']));
    }
}
