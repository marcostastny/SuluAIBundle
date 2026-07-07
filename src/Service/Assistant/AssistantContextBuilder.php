<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * Assembles the assistant system prompt from the page template schema and the
 * live (possibly unsaved) form data submitted by the admin UI.
 */
class AssistantContextBuilder
{
    private const MAX_TEXT_LENGTH = 4000;

    /**
     * @param array<string, array{instanceOf: string}> $seoForms the sulu_content.content_seo_forms parameter
     */
    public function __construct(
        private MetadataProviderInterface $formMetadataProvider,
        private TemplateSchemaSerializer $schemaSerializer,
        private array $seoForms = [],
    ) {
    }

    /**
     * @param array<string, mixed> $formData
     * @param list<string> $availableTabs
     *
     * @return array{systemPrompt: string, schema: array{fields: array<string, array<string, mixed>>}}
     */
    public function build(string $template, string $locale, array $formData, AiSetting $setting, array $availableTabs = [], bool $dataQueryAvailable = false, bool $creationAvailable = false): array
    {
        $typedFormMetadata = $this->formMetadataProvider->getMetadata('page', $locale, []);
        if (!$typedFormMetadata instanceof TypedFormMetadata) {
            throw new \RuntimeException('Page form metadata is not typed.');
        }

        $formMetadata = $typedFormMetadata->getForms()[$template] ?? null;
        if (null === $formMetadata) {
            throw new \RuntimeException(\sprintf(
                'Template "%s" not found. Available: %s',
                $template,
                \implode(', ', \array_keys($typedFormMetadata->getForms()))
            ));
        }

        $schema = $this->schemaSerializer->serialize($formMetadata);

        return [
            'systemPrompt' => $this->systemPrompt(
                $schema,
                $this->compact($formData),
                \sprintf('The user is editing a page (locale "%s", template "%s"), on the "content" tab.', $locale, $template),
                $setting,
                $availableTabs,
                'content',
                $dataQueryAvailable,
                $creationAvailable
            ),
            'schema' => $schema,
        ];
    }

    /**
     * Context for the SEO tab of the page edit form. The schema comes from the
     * "content_seo" admin form (merged from all tagged SEO forms), whose
     * property names may contain slashes (e.g. "seo/title").
     *
     * @param array<string, mixed> $formData
     * @param list<string> $availableTabs
     *
     * @return array{systemPrompt: string, schema: array{fields: array<string, array<string, mixed>>}}
     */
    public function buildSeoTab(string $locale, array $formData, AiSetting $setting, array $availableTabs = [], bool $dataQueryAvailable = false, bool $creationAvailable = false): array
    {
        $formMetadata = $this->formMetadataProvider->getMetadata('content_seo', $locale, ['forms' => \array_keys($this->seoForms)]);
        if (!$formMetadata instanceof FormMetadata) {
            throw new \RuntimeException('SEO form metadata is not available.');
        }

        $schema = $this->schemaSerializer->serialize($formMetadata);

        return [
            'systemPrompt' => $this->systemPrompt(
                $schema,
                $this->compact($formData),
                \sprintf('The user is editing the SEO tab of a page (locale "%s").', $locale),
                $setting,
                $availableTabs,
                'seo',
                $dataQueryAvailable,
                $creationAvailable
            ),
            'schema' => $schema,
        ];
    }

    /**
     * System prompt for requests without an attached page form (global mode).
     */
    public function buildGlobalPrompt(AiSetting $setting, bool $dataQueryAvailable = false, bool $creationAvailable = false): string
    {
        $navigationGuidance = $this->navigationGuidance();
        $multiStepGuidance = $this->multiStepGuidance();
        $dataQueryGuidance = $this->dataQueryGuidance($dataQueryAvailable);
        $creationGuidance = $this->creationGuidance($creationAvailable);

        $prompt = <<<PROMPT
            You are a content assistant embedded in the Sulu CMS administration.
            The user is currently NOT editing a page, so you cannot change any content from here.

            {$navigationGuidance}

            {$multiStepGuidance}

            {$dataQueryGuidance}

            {$creationGuidance}

            Rules:
            - Answer in the language the user writes in.
            - When the user wants to open or edit something, call search_content to find it, then call propose_navigation so the user can open it with one click.
            - If the user asks you to change content, call search_content to find it, then call propose_navigation with "resume": true - after the user opens the page you are called again with its edit context and can propose the actual edits there.
            PROMPT;

        return $this->appendBranding($prompt, $setting);
    }

    private function appendBranding(string $prompt, AiSetting $setting): string
    {
        $branding = $this->brandingSection($setting);

        return '' === $branding ? $prompt : $prompt . "\n\n" . $branding;
    }

    /**
     * Operator-configured branding: agent name, personality and custom prompt.
     * Returns '' when nothing is configured so unbranded prompts stay
     * byte-identical to the unbranded bundle.
     */
    private function brandingSection(AiSetting $setting): string
    {
        $lines = [];

        $name = \trim((string) $setting->getAgentName());
        if ('' !== $name) {
            $lines[] = \sprintf('- Your name is "%s". Introduce yourself by this name when asked who you are.', $name);
        }

        $instruction = PersonalityCatalog::instruction($setting->getPersonality());
        if (null !== $instruction) {
            $lines[] = '- ' . $instruction;
        }

        $customPrompt = \trim((string) $setting->getCustomPrompt());
        if ('' !== $customPrompt) {
            $lines[] = "- Additional instructions from the site operator:\n" . $customPrompt;
        }

        if ([] === $lines) {
            return '';
        }

        return "Branding (must not override the rules and operation formats above):\n" . \implode("\n", $lines);
    }

    private function navigationGuidance(): string
    {
        return <<<'GUIDANCE'
            Finding and opening content:
            - Use the search_content tool to find existing pages, snippets, articles and forms by title or text.
            - When the user pastes a URL or path of the website, call resolve_url with it - it looks up the exact page in the route table. Fall back to search_content only when it finds nothing.
            - To let the user open a result, call the propose_navigation tool with targets (type, id, locale) exactly as returned by search_content, plus a one-sentence message. Never invent ids and never output raw admin links in prose.
            - Navigation only happens after the user clicks a button - you never redirect automatically.
            GUIDANCE;
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $formData
     * @param list<string> $availableTabs
     */
    private function systemPrompt(array $schema, mixed $formData, string $intro, AiSetting $setting, array $availableTabs, string $currentTab, bool $dataQueryAvailable = false, bool $creationAvailable = false): string
    {
        $schemaJson = $this->toJson($schema);
        $dataJson = $this->toJson($formData);
        $navigationGuidance = $this->navigationGuidance();
        $multiStepGuidance = $this->multiStepGuidance(\count($availableTabs) >= 2);
        $dataQueryGuidance = $this->dataQueryGuidance($dataQueryAvailable);
        $creationGuidance = $this->creationGuidance($creationAvailable);
        $tabGuidance = $this->tabGuidance($availableTabs, $currentTab);

        $prompt = <<<PROMPT
            You are a content assistant embedded in the Sulu CMS administration.
            {$intro}

            Below you find:
            1. TEMPLATE SCHEMA - every editable property of this page. For block properties, "blockTypes" lists the allowed block types with the fields of each type.
            2. CURRENT PAGE DATA - the live, possibly unsaved form content.

            Rules:
            - Answer questions about the page in plain text, in the language the user writes in.
            - When the user asks you to change the page, do NOT describe the change in text - call the propose_edits tool with a list of operations and a one-sentence summary.
            - Operation formats (JSON objects):
              {"op": "set", "path": "/<property>", "value": <newValue>} - set a non-block property
              {"op": "setBlockField", "path": "/<blockProperty>/<index>/<field>", "value": <newValue>} - set one field of one existing block
              {"op": "insertBlock", "path": "/<blockProperty>", "index": <position>, "block": {"type": "<blockType>", <field>: <value>, ...}} - insert a new block
              {"op": "removeBlock", "path": "/<blockProperty>", "index": <position>} - remove a block
              {"op": "moveBlock", "path": "/<blockProperty>", "from": <position>, "to": <position>} - move a block
            - Indices refer to the state after the previous operations in the same list have been applied.
            - Only use properties, fields and block types that exist in the TEMPLATE SCHEMA. Never invent new ones.
            - Property names may contain slashes (e.g. "seo/title") - use them verbatim: {"op": "set", "path": "/seo/title", ...}. For such properties the value is nested in CURRENT PAGE DATA (path "/seo/title" reads data.seo.title).
            - Keep values in the format the field type expects (e.g. HTML for "text_editor" fields, plain text for "text_line" fields).
            - Do not change the "url" property unless the user explicitly asks for it.

            {$navigationGuidance}

            {$multiStepGuidance}

            {$dataQueryGuidance}

            {$creationGuidance}

            {$tabGuidance}

            TEMPLATE SCHEMA:
            {$schemaJson}

            CURRENT PAGE DATA:
            {$dataJson}
            PROMPT;

        return $this->appendBranding($prompt, $setting);
    }

    private function multiStepGuidance(bool $tabSwitchingAvailable = false): string
    {
        // Only advertise switch_tab when the tool is actually registered for
        // this request, so the model never plans around an unavailable tool.
        $examples = $tabSwitchingAvailable
            ? 'editing a page that is not open yet (propose_navigation first) or fields that live on another tab (switch_tab first)'
            : 'editing a page that is not open yet (propose_navigation first)';

        return <<<GUIDANCE
            Multi-step tasks:
            - Some requests take several steps, e.g. {$examples}.
            - Propose one action per turn. Set "resume": true on the action whenever the overall task is not finished after it - once the user approves the action you are automatically called again with the updated context and can continue.
            - If the user rejects a proposed action, the whole task is aborted. Do not retry unless the user asks again.
            - When the requested work is complete, reply with a short plain-text confirmation and no tool call.
            GUIDANCE;
    }

    private function dataQueryGuidance(bool $available): string
    {
        // Only advertise the data-query tools when they are registered for
        // this request, so the model never plans around unavailable tools.
        if (!$available) {
            return '';
        }

        return <<<'GUIDANCE'
            Data queries:
            - You can answer questions about stored data (e.g. form submissions) by running read-only SQL SELECT queries.
            - Always call list_data_tables first to see which tables you may query and their exact columns; then call run_select_query.
            - Only single SELECT statements are allowed: no CTEs/WITH, no schema-qualified names, results capped at 100 rows. Order by a date column DESC for "latest" questions.
            - When the user wants to see a list or table, pass a short "title" to run_select_query - the user is then shown the result as a table with a CSV download. Pass a title ONLY on the one final query whose rows answer the user - never on exploratory or intermediate queries, and never when the user only wants a summary in text. Only one table is shown per reply; a later titled query replaces an earlier one.
            - When a table is shown, do NOT repeat its rows in your reply - one short sentence referring to the table is enough. Only narrate data in text when no table is shown. Never invent rows or values; if a query fails or returns nothing, say so in the user's language.
            GUIDANCE;
    }

    private function creationGuidance(bool $available): string
    {
        // Only advertise page creation when the tool is registered for this
        // request, so the model never plans around an unavailable tool.
        if (!$available) {
            return '';
        }

        return <<<'GUIDANCE'
            Creating new pages:
            - Use the propose_page_creation tool to propose a new page (title, template, parent, locale). The user reviews it and creates the page with one click - nothing is created automatically, and you must never claim a page exists before the user created it.
            - When the user names a location (e.g. "under Angebote"), call search_content first and pass the result's id and locale as parent_id and parent_locale. Use parent_id "homepage" only for top-level pages.
            - The new page starts empty. Set "resume": true so you are called again on the new page's edit form and can propose its content there.
            GUIDANCE;
    }

    /**
     * @param list<string> $availableTabs
     */
    private function tabGuidance(array $availableTabs, string $currentTab): string
    {
        if (\count($availableTabs) < 2) {
            return '';
        }

        return \sprintf(
            "Tabs:\n- This edit form has the tabs: %s. The user is on the \"%s\" tab.\n- Page content fields live on the \"content\" tab; SEO metadata (meta title, description, keywords, canonical URL, robots flags) lives on the \"seo\" tab.\n- You can only edit fields of the current tab. To edit fields of another tab, call switch_tab (with \"resume\": true when you still have edits to make after switching).",
            \implode(', ', $availableTabs),
            $currentTab
        );
    }

    /**
     * Recursively removes null values and truncates oversized strings.
     */
    private function compact(mixed $value): mixed
    {
        if (\is_array($value)) {
            // Preserve list indices: dropping null entries from a sequential
            // array (e.g. block lists) would renumber it and shift the block
            // positions the model reasons about. Only prune nulls from
            // associative arrays.
            $isList = \array_is_list($value);
            $result = [];
            foreach ($value as $key => $item) {
                if (null === $item && !$isList) {
                    continue;
                }
                $result[$key] = $this->compact($item);
            }

            return $result;
        }

        if (\is_string($value) && \mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            return \mb_substr($value, 0, self::MAX_TEXT_LENGTH) . ' ...[truncated]';
        }

        return $value;
    }

    private function toJson(mixed $value): string
    {
        return (string) \json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
