<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use CmsIg\Seal\EngineInterface;
use CmsIg\Seal\Search\Condition\Condition;

/**
 * Queries the SEAL "website" search index, which — unlike the title-only
 * "admin" index — contains the full searchable text of published pages
 * (every property tagged sulu.search.field, including nested block fields).
 * Results are normalized to the admin-index document shape so the existing
 * NavigationTargetResolver can turn them into edit-form targets.
 */
class WebsiteIndexSearcher
{
    private const INDEX = 'website';

    public function __construct(
        private EngineInterface $engine,
        private EditableSecurityContexts $editableContexts,
    ) {
    }

    /**
     * @param list<string> $resourceKeys
     *
     * @return list<array<string, mixed>> documents in the admin-index shape
     */
    public function search(string $query, array $resourceKeys, ?string $locale, int $limit): array
    {
        $contexts = $this->editableContexts->all();
        if ([] === $contexts) {
            return [];
        }

        $search = $this->engine->createSearchBuilder(self::INDEX)
            ->addFilter(Condition::search($query))
            ->addFilter(Condition::in('resourceKey', $resourceKeys))
            ->limit($limit);

        if (null !== $locale && '' !== $locale) {
            $search = $search->addFilter(Condition::equal('locale', $locale));
        }

        $documents = [];
        foreach ($search->getResult() as $document) {
            // The website index has no securityContext field, so the
            // permission filter runs here: keep only documents of a webspace
            // the user may edit.
            $webspace = $this->editableWebspace($document, $contexts);
            if (null === $webspace) {
                continue;
            }

            $document['metadata'] = ['webspaceKey' => $webspace];
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $contexts
     */
    private function editableWebspace(array $document, array $contexts): ?string
    {
        $webspaces = \is_array($document['webspaces'] ?? null) ? $document['webspaces'] : [];
        foreach ($webspaces as $webspace) {
            if (\in_array('sulu.webspaces.' . $webspace, $contexts, true)) {
                return (string) $webspace;
            }
        }

        return null;
    }
}
