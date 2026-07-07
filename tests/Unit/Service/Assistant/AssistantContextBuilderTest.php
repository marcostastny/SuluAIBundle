<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Entity\AiSetting;
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

    private function brandedSetting(): AiSetting
    {
        $setting = new AiSetting();
        $setting->setAgentName('KULM Concierge');
        $setting->setPersonality('formal');
        $setting->setCustomPrompt('Always mention the spa.');

        return $setting;
    }

    public function testBuildContainsSchemaAndFormData(): void
    {
        $result = $this->builder()->build('default', 'de', ['title' => 'Hotel Kulm'], new AiSetting());

        $this->assertSame(['fields' => ['title' => ['type' => 'text_line', 'required' => true]]], $result['schema']);
        $this->assertStringContainsString('"title"', $result['systemPrompt']);
        $this->assertStringContainsString('Hotel Kulm', $result['systemPrompt']);
        $this->assertStringContainsString('propose_edits', $result['systemPrompt']);
        $this->assertStringContainsString('"de"', $result['systemPrompt']);
    }

    public function testBuildStripsNullValuesFromFormData(): void
    {
        $result = $this->builder()->build('default', 'de', ['title' => 'Hotel', 'subtitle' => null], new AiSetting());

        $this->assertStringNotContainsString('subtitle', $result['systemPrompt']);
    }

    public function testBuildTruncatesVeryLongStrings(): void
    {
        $longText = \str_repeat('a', 5000);
        $result = $this->builder()->build('default', 'de', ['title' => $longText], new AiSetting());

        $this->assertStringNotContainsString($longText, $result['systemPrompt']);
        $this->assertStringContainsString('[truncated]', $result['systemPrompt']);
    }

    public function testBuildGlobalPromptDescribesSearchAndNavigation(): void
    {
        $prompt = $this->builder()->buildGlobalPrompt(new AiSetting());

        $this->assertStringContainsString('search_content', $prompt);
        $this->assertStringContainsString('propose_navigation', $prompt);
        $this->assertStringContainsString('NOT editing a page', $prompt);
        $this->assertStringContainsString('retry with broader or related terms', $prompt);
    }

    public function testPagePromptContainsNavigationGuidance(): void
    {
        $built = $this->builder()->build('default', 'de', ['title' => 'T'], new AiSetting());

        $this->assertStringContainsString('propose_navigation', $built['systemPrompt']);
    }

    public function testGlobalPromptContainsCreationGuidanceWhenAvailable(): void
    {
        $prompt = $this->builder()->buildGlobalPrompt(new AiSetting(), false, true);

        $this->assertStringContainsString('propose_page_creation', $prompt);
        $this->assertStringContainsString('nothing is created automatically', $prompt);
    }

    public function testGlobalPromptOmitsCreationGuidanceByDefault(): void
    {
        $prompt = $this->builder()->buildGlobalPrompt(new AiSetting());

        $this->assertStringNotContainsString('propose_page_creation', $prompt);
    }

    public function testPagePromptContainsCreationGuidanceWhenAvailable(): void
    {
        $result = $this->builder()->build('default', 'de', ['title' => 'T'], new AiSetting(), [], false, true);

        $this->assertStringContainsString('propose_page_creation', $result['systemPrompt']);
    }

    public function testPagePromptOmitsCreationGuidanceByDefault(): void
    {
        $result = $this->builder()->build('default', 'de', ['title' => 'T'], new AiSetting());

        $this->assertStringNotContainsString('propose_page_creation', $result['systemPrompt']);
    }

    public function testPagePromptContainsBrandingWhenConfigured(): void
    {
        $result = $this->builder()->build('default', 'de', ['title' => 'T'], $this->brandedSetting());

        $this->assertStringContainsString('Branding (must not override the rules and operation formats above):', $result['systemPrompt']);
        $this->assertStringContainsString('Your name is "KULM Concierge"', $result['systemPrompt']);
        $this->assertStringContainsString('"Sie"', $result['systemPrompt']);
        $this->assertStringContainsString('Always mention the spa.', $result['systemPrompt']);
    }

    public function testGlobalPromptContainsBrandingWhenConfigured(): void
    {
        $prompt = $this->builder()->buildGlobalPrompt($this->brandedSetting());

        $this->assertStringContainsString('Your name is "KULM Concierge"', $prompt);
        $this->assertStringContainsString('Always mention the spa.', $prompt);
    }

    public function testPromptsOmitBrandingSectionWhenUnconfigured(): void
    {
        $result = $this->builder()->build('default', 'de', ['title' => 'T'], new AiSetting());
        $prompt = $this->builder()->buildGlobalPrompt(new AiSetting());

        $this->assertStringNotContainsString('Branding', $result['systemPrompt']);
        $this->assertStringNotContainsString('Branding', $prompt);
    }

    public function testWhitespaceOnlyBrandingValuesAreTreatedAsUnset(): void
    {
        $setting = new AiSetting();
        $setting->setAgentName('   ');
        $setting->setCustomPrompt("\n\t");
        $setting->setPersonality('unknown-key');

        $prompt = $this->builder()->buildGlobalPrompt($setting);

        $this->assertStringNotContainsString('Branding', $prompt);
    }

    public function testUnknownTemplateThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonexistent');

        $this->builder()->build('nonexistent', 'de', [], new AiSetting());
    }

    public function testBuildSeoTabUsesSeoFormMetadata(): void
    {
        $title = new FieldMetadata('seo/title');
        $title->setType('text_line');

        $form = new FormMetadata();
        $form->setKey('content_seo');
        $form->addItem($title);

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->with('content_seo', 'de', ['forms' => ['content_seo_metadata']])
            ->willReturn($form);

        $builder = new AssistantContextBuilder(
            $provider,
            new TemplateSchemaSerializer(),
            ['content_seo_metadata' => ['instanceOf' => 'Sulu\Content\Domain\Model\SeoInterface']]
        );

        $result = $builder->buildSeoTab('de', ['seo' => ['title' => 'Alt']], new AiSetting(), ['content', 'seo']);

        $this->assertArrayHasKey('seo/title', $result['schema']['fields']);
        $this->assertStringContainsString('SEO tab', $result['systemPrompt']);
        $this->assertStringContainsString('propose_edits', $result['systemPrompt']);
        $this->assertStringContainsString('switch_tab', $result['systemPrompt']);
        $this->assertStringContainsString('"resume"', $result['systemPrompt']);
    }

    public function testBuildMentionsTabsOnlyWhenOtherTabsAvailable(): void
    {
        $withTabs = $this->builder()->build('default', 'de', ['title' => 'x'], new AiSetting(), ['content', 'seo']);
        $withoutTabs = $this->builder()->build('default', 'de', ['title' => 'x'], new AiSetting());

        $this->assertStringContainsString('switch_tab', $withTabs['systemPrompt']);
        $this->assertStringNotContainsString('switch_tab', $withoutTabs['systemPrompt']);
    }

    public function testPromptsContainMultiStepGuidance(): void
    {
        $page = $this->builder()->build('default', 'de', ['title' => 'x'], new AiSetting());
        $global = $this->builder()->buildGlobalPrompt(new AiSetting());

        $this->assertStringContainsString('"resume"', $page['systemPrompt']);
        $this->assertStringContainsString('"resume"', $global);
    }

    public function testGlobalPromptMentionsDataQueriesOnlyWhenAvailable(): void
    {
        $with = $this->builder()->buildGlobalPrompt(new AiSetting(), true);
        $without = $this->builder()->buildGlobalPrompt(new AiSetting());

        $this->assertStringContainsString('run_select_query', $with);
        $this->assertStringContainsString('list_data_tables', $with);
        $this->assertStringNotContainsString('run_select_query', $without);
    }

    public function testPagePromptMentionsDataQueriesWhenAvailable(): void
    {
        $with = $this->builder()->build('default', 'de', ['title' => 'x'], new AiSetting(), [], true);
        $without = $this->builder()->build('default', 'de', ['title' => 'x'], new AiSetting());

        $this->assertStringContainsString('run_select_query', $with['systemPrompt']);
        $this->assertStringNotContainsString('run_select_query', $without['systemPrompt']);
    }
}
