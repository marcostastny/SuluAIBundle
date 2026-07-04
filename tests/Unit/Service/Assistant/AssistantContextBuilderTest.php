<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\AssistantContextBuilder;
use Marcostastny\SuluAIBundle\Service\Assistant\TemplateSchemaSerializer;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

class AssistantContextBuilderTest extends TestCase
{
    private function builder(): AssistantContextBuilder
    {
        $title = new FieldMetadata('title');
        $title->setType('text_line');
        $title->setRequired(true);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($title);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $form);

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')->with('page', 'de', [])->willReturn($typed);

        return new AssistantContextBuilder($provider, new TemplateSchemaSerializer());
    }

    public function testBuildContainsSchemaAndFormData(): void
    {
        $result = $this->builder()->build('default', 'de', ['title' => 'Hotel Kulm']);

        $this->assertSame(['fields' => ['title' => ['type' => 'text_line', 'required' => true]]], $result['schema']);
        $this->assertStringContainsString('"title"', $result['systemPrompt']);
        $this->assertStringContainsString('Hotel Kulm', $result['systemPrompt']);
        $this->assertStringContainsString('propose_edits', $result['systemPrompt']);
        $this->assertStringContainsString('"de"', $result['systemPrompt']);
    }

    public function testBuildStripsNullValuesFromFormData(): void
    {
        $result = $this->builder()->build('default', 'de', ['title' => 'Hotel', 'article' => null]);

        $this->assertStringNotContainsString('article', $result['systemPrompt']);
    }

    public function testBuildTruncatesVeryLongStrings(): void
    {
        $longText = \str_repeat('a', 5000);
        $result = $this->builder()->build('default', 'de', ['title' => $longText]);

        $this->assertStringNotContainsString($longText, $result['systemPrompt']);
        $this->assertStringContainsString('[truncated]', $result['systemPrompt']);
    }

    public function testUnknownTemplateThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonexistent');

        $this->builder()->build('nonexistent', 'de', []);
    }
}
