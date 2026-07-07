<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\Tool;

use Marcostastny\SuluAIBundle\Service\Assistant\AdminIndexSearcher;
use Marcostastny\SuluAIBundle\Service\Assistant\FormTitleSearcher;
use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetResolver;
use Marcostastny\SuluAIBundle\Service\Assistant\Tool\SearchContentTool;
use Marcostastny\SuluAIBundle\Service\Assistant\WebsiteIndexSearcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchContentToolTest extends TestCase
{
    private AdminIndexSearcher&MockObject $indexSearcher;
    private WebsiteIndexSearcher&MockObject $websiteSearcher;
    private FormTitleSearcher&MockObject $formSearcher;
    private NavigationTargetCollector $collector;

    private function tool(array $adminResources = ['pages' => [], 'snippets' => [], 'articles' => []]): SearchContentTool
    {
        $this->indexSearcher = $this->createMock(AdminIndexSearcher::class);
        $this->websiteSearcher = $this->createMock(WebsiteIndexSearcher::class);
        $this->formSearcher = $this->createMock(FormTitleSearcher::class);
        $this->formSearcher->method('isAvailable')->willReturn(true);
        $this->collector = new NavigationTargetCollector();

        return new SearchContentTool(
            $this->indexSearcher,
            $this->websiteSearcher,
            $this->formSearcher,
            new NavigationTargetResolver([
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
            ]),
            $this->collector,
            $adminResources
        );
    }

    private function pageDocument(): array
    {
        return [
            'resourceKey' => 'pages',
            'resourceId' => '42',
            'locale' => 'de',
            'title' => 'Zimmer',
            'metadata' => ['webspaceKey' => 'kulm'],
        ];
    }

    public function testDefinitionListsAvailableTypes(): void
    {
        $definition = $this->tool()->getDefinition();

        $this->assertSame('search_content', $definition['function']['name']);
        $this->assertSame(
            ['pages', 'snippets', 'articles', 'forms'],
            $definition['function']['parameters']['properties']['types']['items']['enum']
        );
    }

    public function testDefinitionOmitsTypesWithoutSearchResource(): void
    {
        $definition = $this->tool(['pages' => []])->getDefinition();

        $this->assertSame(
            ['pages', 'forms'],
            $definition['function']['parameters']['properties']['types']['items']['enum']
        );
    }

    public function testEmptyQueryReturnsError(): void
    {
        $this->assertArrayHasKey('error', $this->tool()->execute(['query' => '  ']));
    }

    public function testSearchReturnsStrippedResultsAndCollectsFullTargets(): void
    {
        $tool = $this->tool();
        $this->indexSearcher->method('search')->willReturn([$this->pageDocument()]);
        $this->formSearcher->method('search')->willReturn([]);

        $result = $tool->execute(['query' => 'zimmer']);

        $this->assertSame(
            [['type' => 'pages', 'id' => '42', 'locale' => 'de', 'title' => 'Zimmer']],
            $result['results']
        );

        $collected = $this->collector->get('pages', '42', 'de');
        $this->assertNotNull($collected);
        $this->assertSame('sulu_page.page_edit_form', $collected['view']);
        $this->assertSame(['id' => '42', 'locale' => 'de', 'webspace' => 'kulm'], $collected['attributes']);
    }

    public function testTypesFilterSkipsIndexWhenOnlyFormsRequested(): void
    {
        $tool = $this->tool();
        $this->indexSearcher->expects($this->never())->method('search');
        $this->formSearcher->method('search')->willReturn([[
            'type' => 'forms',
            'id' => '3',
            'locale' => 'de',
            'title' => 'Tischreservierung',
            'view' => 'sulu_form.edit_form',
            'attributes' => ['id' => 3, 'locale' => 'de'],
        ]]);

        $result = $tool->execute(['query' => 'reservierung', 'types' => ['forms']]);

        $this->assertCount(1, $result['results']);
        $this->assertSame('forms', $result['results'][0]['type']);
        $this->assertNotNull($this->collector->get('forms', '3', 'de'));
    }

    public function testIndexFailureReturnsNoteInsteadOfThrowing(): void
    {
        $tool = $this->tool();
        $this->indexSearcher->method('search')->willThrowException(new \RuntimeException('index down'));

        $result = $tool->execute(['query' => 'zimmer', 'types' => ['pages']]);

        $this->assertSame([], $result['results']);
        $this->assertArrayHasKey('note', $result);
    }

    public function testWebsiteResultsAreMergedAndDeduplicated(): void
    {
        $tool = $this->tool();
        $this->indexSearcher->method('search')->willReturn([$this->pageDocument()]);
        $this->websiteSearcher->method('search')->willReturn([
            $this->pageDocument(), // also matched by title in the admin index
            [
                'resourceKey' => 'pages',
                'resourceId' => '77',
                'locale' => 'de',
                'title' => 'Preise',
                'metadata' => ['webspaceKey' => 'kulm'],
            ],
        ]);
        $this->formSearcher->method('search')->willReturn([]);

        $result = $tool->execute(['query' => 'kurtaxe']);

        $this->assertSame([
            ['type' => 'pages', 'id' => '42', 'locale' => 'de', 'title' => 'Zimmer'],
            ['type' => 'pages', 'id' => '77', 'locale' => 'de', 'title' => 'Preise'],
        ], $result['results']);
        $this->assertNotNull($this->collector->get('pages', '77', 'de'));
    }

    public function testWebsiteIndexFailureKeepsAdminResults(): void
    {
        $tool = $this->tool();
        $this->indexSearcher->method('search')->willReturn([$this->pageDocument()]);
        $this->websiteSearcher->method('search')->willThrowException(new \RuntimeException('website index down'));
        $this->formSearcher->method('search')->willReturn([]);

        $result = $tool->execute(['query' => 'zimmer']);

        $this->assertCount(1, $result['results']);
        $this->assertArrayNotHasKey('note', $result);
    }

    public function testUnresolvableDocumentIsSkipped(): void
    {
        $tool = $this->tool();
        $this->indexSearcher->method('search')->willReturn([
            ['resourceKey' => 'snippets', 'resourceId' => '9', 'locale' => 'de', 'title' => 'S'],
            $this->pageDocument(),
        ]);
        $this->formSearcher->method('search')->willReturn([]);

        $result = $tool->execute(['query' => 'x']);

        $this->assertCount(1, $result['results']);
        $this->assertSame('pages', $result['results'][0]['type']);
    }
}
