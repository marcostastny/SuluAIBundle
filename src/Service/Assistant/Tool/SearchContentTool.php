<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\Tool;

use Marcostastny\SuluAIBundle\Service\Assistant\AdminIndexSearcher;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantToolInterface;
use Marcostastny\SuluAIBundle\Service\Assistant\FormTitleSearcher;
use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetResolver;

/**
 * Lets the assistant find existing content. Index-backed types come from the
 * SEAL admin index (permission-filtered), forms from a Doctrine title lookup.
 * Full navigation targets are recorded in the collector; the model only sees
 * type/id/locale/title so it must reference results instead of inventing routes.
 */
class SearchContentTool implements AssistantToolInterface
{
    private const LIMIT = 10;
    private const INDEX_TYPES = ['pages', 'snippets', 'articles'];

    /**
     * @param array<string, array<string, mixed>> $adminResources the sulu_search.admin_resources parameter
     */
    public function __construct(
        private AdminIndexSearcher $indexSearcher,
        private FormTitleSearcher $formSearcher,
        private NavigationTargetResolver $targetResolver,
        private NavigationTargetCollector $targetCollector,
        private array $adminResources,
    ) {
    }

    public function getName(): string
    {
        return 'search_content';
    }

    public function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_content',
                'description' => 'Search the CMS for existing content by title or text. Returns matching items with their type, id, locale and title. Always use this before proposing navigation.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search words, e.g. the title the user mentioned.',
                        ],
                        'types' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => $this->availableTypes()],
                            'description' => 'Restrict the search to these content types. Omit to search all types.',
                        ],
                        'locale' => [
                            'type' => 'string',
                            'description' => 'Restrict results to one locale, e.g. "de".',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $query = \trim((string) ($arguments['query'] ?? ''));
        if ('' === $query) {
            return ['error' => 'query must not be empty.'];
        }

        $requested = \is_array($arguments['types'] ?? null) ? $arguments['types'] : [];
        $types = \array_values(\array_intersect($requested, $this->availableTypes()));
        if ([] === $types) {
            $types = $this->availableTypes();
        }

        $locale = isset($arguments['locale']) ? (string) $arguments['locale'] : null;

        $results = [];

        $indexTypes = \array_values(\array_intersect($types, self::INDEX_TYPES));
        if ([] !== $indexTypes) {
            try {
                foreach ($this->indexSearcher->search($query, $indexTypes, $locale, self::LIMIT) as $document) {
                    $target = $this->targetResolver->resolve($document);
                    if (null === $target) {
                        continue;
                    }

                    $results[] = [
                        'type' => (string) ($document['resourceKey'] ?? ''),
                        'id' => (string) ($document['resourceId'] ?? ''),
                        'locale' => (string) ($document['locale'] ?? ''),
                        'title' => (string) ($document['title'] ?? ''),
                        'view' => $target['view'],
                        'attributes' => $target['attributes'],
                    ];
                }
            } catch (\Throwable) {
                return [
                    'results' => [],
                    'note' => 'The search index is unavailable. Tell the user that searching is currently not possible.',
                ];
            }
        }

        $note = null;
        if (\in_array('forms', $types, true)) {
            try {
                $results = [...$results, ...$this->formSearcher->search($query, $locale, self::LIMIT)];
            } catch (\Throwable) {
                // Degrade like the index path does rather than failing the turn.
                $note = 'Form search is currently unavailable; only other results (if any) are shown.';
            }
        }

        foreach ($results as $result) {
            $this->targetCollector->add($result);
        }

        $response = [
            'results' => \array_map(
                static fn (array $result): array => \array_diff_key($result, ['view' => true, 'attributes' => true]),
                $results
            ),
        ];
        if (null !== $note) {
            $response['note'] = $note;
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private function availableTypes(): array
    {
        $types = \array_values(\array_intersect(self::INDEX_TYPES, \array_keys($this->adminResources)));
        if ($this->formSearcher->isAvailable()) {
            $types[] = 'forms';
        }

        return $types;
    }
}
