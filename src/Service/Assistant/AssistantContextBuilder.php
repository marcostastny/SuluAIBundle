<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * Assembles the assistant system prompt from the page template schema and the
 * live (possibly unsaved) form data submitted by the admin UI.
 */
class AssistantContextBuilder
{
    private const MAX_TEXT_LENGTH = 4000;

    public function __construct(
        private MetadataProviderInterface $formMetadataProvider,
        private TemplateSchemaSerializer $schemaSerializer,
    ) {
    }

    /**
     * @param array<string, mixed> $formData
     *
     * @return array{systemPrompt: string, schema: array{fields: array<string, array<string, mixed>>}}
     */
    public function build(string $template, string $locale, array $formData): array
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
            'systemPrompt' => $this->systemPrompt($schema, $this->compact($formData), $locale, $template),
            'schema' => $schema,
        ];
    }

    /**
     * System prompt for requests without an attached page form (global mode).
     */
    public function buildGlobalPrompt(): string
    {
        $navigationGuidance = $this->navigationGuidance();

        return <<<PROMPT
            You are a content assistant embedded in the Sulu CMS administration.
            The user is currently NOT editing a page, so you cannot change any content from here.

            {$navigationGuidance}

            Rules:
            - Answer in the language the user writes in.
            - When the user wants to open or edit something, call search_content to find it, then call propose_navigation so the user can open it with one click.
            - If the user asks you to change content directly, explain that you can only edit on the content edit form and offer to navigate there.
            PROMPT;
    }

    private function navigationGuidance(): string
    {
        return <<<'GUIDANCE'
            Finding and opening content:
            - Use the search_content tool to find existing pages, snippets, articles and forms by title or text.
            - To let the user open a result, call the propose_navigation tool with targets (type, id, locale) exactly as returned by search_content, plus a one-sentence message. Never invent ids and never output raw admin links in prose.
            - Navigation only happens after the user clicks a button - you never redirect automatically.
            GUIDANCE;
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $formData
     */
    private function systemPrompt(array $schema, mixed $formData, string $locale, string $template): string
    {
        $schemaJson = $this->toJson($schema);
        $dataJson = $this->toJson($formData);
        $navigationGuidance = $this->navigationGuidance();

        return <<<PROMPT
            You are a content assistant embedded in the Sulu CMS administration.
            The user is editing a page (locale "{$locale}", template "{$template}").

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
            - Keep values in the format the field type expects (e.g. HTML for "text_editor" fields, plain text for "text_line" fields).
            - Do not change the "url" property unless the user explicitly asks for it.

            {$navigationGuidance}

            TEMPLATE SCHEMA:
            {$schemaJson}

            CURRENT PAGE DATA:
            {$dataJson}
            PROMPT;
    }

    /**
     * Recursively removes null values and truncates oversized strings.
     */
    private function compact(mixed $value): mixed
    {
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (null === $item) {
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
