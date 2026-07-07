<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\Creation;

use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationGate;
use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationValidator;
use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Route\Application\ResourceLocator\ResourceLocatorGeneratorInterface;

class PageCreationValidatorTest extends TestCase
{
    private NavigationTargetCollector $collector;

    private function createValidator(?string $soleWebspace = 'kulm', string $generatedUrl = '/wellness-weekend'): PageCreationValidator
    {
        $default = new FormMetadata();
        $default->setTitles(['de' => 'Standard']);
        $typed = new TypedFormMetadata();
        $typed->addForm('default', $default);

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')->with('page', 'de', [])->willReturn($typed);

        $generator = $this->createMock(ResourceLocatorGeneratorInterface::class);
        $generator->method('generate')->willReturn($generatedUrl);

        $gate = $this->createMock(PageCreationGate::class);
        $gate->method('soleAllowedWebspaceKey')->willReturn($soleWebspace);

        $this->collector = new NavigationTargetCollector();

        return new PageCreationValidator($provider, $this->collector, $generator, $gate);
    }

    public function testValidHomepageParentProducesAction(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate([
            'title' => 'Wellness Weekend',
            'template' => 'default',
            'parent_id' => 'homepage',
            'locale' => 'de',
            'message' => 'Neue Seite anlegen?',
            'resume' => true,
        ], null);

        $this->assertArrayHasKey('action', $result);
        $this->assertSame('createPage', $result['action']['type']);
        $this->assertSame('Wellness Weekend', $result['action']['title']);
        $this->assertSame('Standard', $result['action']['templateTitle']);
        $this->assertSame('homepage', $result['action']['parentId']);
        $this->assertSame('kulm', $result['action']['webspace']);
        $this->assertSame('/wellness-weekend', $result['action']['url']);
        $this->assertTrue($result['action']['resume']);
    }

    public function testParentFromSearchResultsWinsWebspace(): void
    {
        $validator = $this->createValidator(null);
        $this->collector->add([
            'type' => 'pages', 'id' => 'abc-123', 'locale' => 'de', 'title' => 'Angebote',
            'view' => 'sulu_page.page_edit_form',
            'attributes' => ['id' => 'abc-123', 'locale' => 'de', 'webspace' => 'kulm'],
        ]);

        $result = $validator->validate([
            'title' => 'Wellness Weekend', 'template' => 'default',
            'parent_id' => 'abc-123', 'parent_locale' => 'de', 'locale' => 'de', 'message' => 'x',
        ], null);

        $this->assertArrayHasKey('action', $result);
        $this->assertSame('abc-123', $result['action']['parentId']);
        $this->assertSame('Angebote', $result['action']['parentTitle']);
        $this->assertSame('kulm', $result['action']['webspace']);
    }

    public function testUnknownParentRejected(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate([
            'title' => 'X', 'template' => 'default',
            'parent_id' => 'invented-id', 'parent_locale' => 'de', 'locale' => 'de', 'message' => 'x',
        ], null);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('search_content', $result['errors'][0]);
    }

    public function testUnknownTemplateRejectedWithAvailableList(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate([
            'title' => 'X', 'template' => 'missing', 'parent_id' => 'homepage', 'locale' => 'de', 'message' => 'x',
        ], null);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('default', $result['errors'][0]);
    }

    public function testAmbiguousWebspaceRejected(): void
    {
        $validator = $this->createValidator(null);

        $result = $validator->validate([
            'title' => 'X', 'template' => 'default', 'parent_id' => 'homepage', 'locale' => 'de', 'message' => 'x',
        ], null);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('webspace', $result['errors'][0]);
    }

    public function testEmptyTitleRejected(): void
    {
        $validator = $this->createValidator();

        $result = $validator->validate([
            'title' => ' ', 'template' => 'default', 'parent_id' => 'homepage', 'locale' => 'de', 'message' => 'x',
        ], null);

        $this->assertArrayHasKey('errors', $result);
    }
}
