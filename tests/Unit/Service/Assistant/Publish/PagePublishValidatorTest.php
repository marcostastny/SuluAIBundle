<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\Publish;

use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\Publish\PagePublishGate;
use Marcostastny\SuluAIBundle\Service\Assistant\Publish\PagePublishValidator;
use PHPUnit\Framework\TestCase;

class PagePublishValidatorTest extends TestCase
{
    private NavigationTargetCollector $collector;

    private const CONTEXT_PAGE = [
        'id' => 'ctx-1',
        'locale' => 'de',
        'webspace' => 'kulm',
        'title' => 'Zimmer & Preise',
    ];

    private function createValidator(array $allowedWebspaces = ['kulm']): PagePublishValidator
    {
        $gate = $this->createMock(PagePublishGate::class);
        $gate->method('allowsWebspace')
            ->willReturnCallback(fn (string $key): bool => \in_array($key, $allowedWebspaces, true));

        $this->collector = new NavigationTargetCollector();

        return new PagePublishValidator($this->collector, $gate);
    }

    public function testCurrentPageTargetProducesAction(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate([
            'mode' => 'publish',
            'message' => 'Seite veröffentlichen?',
            'resume' => true,
        ], self::CONTEXT_PAGE);

        $this->assertArrayHasKey('action', $result);
        $this->assertSame('publishPage', $result['action']['type']);
        $this->assertSame('publish', $result['action']['mode']);
        $this->assertSame('ctx-1', $result['action']['id']);
        $this->assertSame('Zimmer & Preise', $result['action']['title']);
        $this->assertSame('de', $result['action']['locale']);
        $this->assertSame('kulm', $result['action']['webspace']);
        $this->assertSame('Seite veröffentlichen?', $result['action']['message']);
        $this->assertTrue($result['action']['resume']);
    }

    public function testUnpublishModeIsAccepted(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate(['mode' => 'unpublish', 'message' => 'x'], self::CONTEXT_PAGE);

        $this->assertArrayHasKey('action', $result);
        $this->assertSame('unpublish', $result['action']['mode']);
        $this->assertFalse($result['action']['resume']);
    }

    public function testInvalidModeIsRejected(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate(['mode' => 'delete', 'message' => 'x'], self::CONTEXT_PAGE);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('mode must be "publish" or "unpublish"', $result['errors'][0]);
    }

    public function testNoIdWithoutOpenPageIsRejected(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate(['mode' => 'publish', 'message' => 'x'], null);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('No page is open', $result['errors'][0]);
    }

    public function testSearchResultTargetProducesAction(): void
    {
        $validator = $this->createValidator();
        $this->collector->add([
            'type' => 'pages', 'id' => 'abc-123', 'locale' => 'de', 'title' => 'Angebote',
            'view' => 'sulu_page.page_edit_form',
            'attributes' => ['id' => 'abc-123', 'locale' => 'de', 'webspace' => 'kulm'],
        ]);

        $result = $validator->validate([
            'mode' => 'publish', 'id' => 'abc-123', 'locale' => 'de', 'message' => 'x',
        ], null);

        $this->assertArrayHasKey('action', $result);
        $this->assertSame('abc-123', $result['action']['id']);
        $this->assertSame('Angebote', $result['action']['title']);
        $this->assertSame('kulm', $result['action']['webspace']);
    }

    public function testUnknownTargetIsRejected(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate([
            'mode' => 'publish', 'id' => 'ghost-1', 'locale' => 'de', 'message' => 'x',
        ], self::CONTEXT_PAGE);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('was not returned by search_content', $result['errors'][0]);
    }

    public function testWebspaceWithoutLivePermissionIsRejected(): void
    {
        $validator = $this->createValidator([]);

        $result = $validator->validate(['mode' => 'publish', 'message' => 'x'], self::CONTEXT_PAGE);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('not allowed to publish', $result['errors'][0]);
    }

    public function testUnknownWebspaceIsRejected(): void
    {
        $validator = $this->createValidator();
        $this->collector->add([
            'type' => 'pages', 'id' => 'abc-123', 'locale' => 'de', 'title' => 'Angebote',
            'view' => 'sulu_page.page_edit_form',
            'attributes' => ['id' => 'abc-123', 'locale' => 'de'],
        ]);

        $result = $validator->validate([
            'mode' => 'publish', 'id' => 'abc-123', 'locale' => 'de', 'message' => 'x',
        ], null);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('webspace could not be determined', $result['errors'][0]);
    }
}
