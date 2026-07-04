<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\ImageGeneration;

use Sulu\Bundle\MediaBundle\Collection\Manager\CollectionManagerInterface;

/**
 * Resolves (and lazily creates) the shared "AI Created" media collection that
 * generated images are stored in.
 */
class AiCreatedCollection
{
    public const KEY = 'ai_created';

    public function __construct(private CollectionManagerInterface $collectionManager)
    {
    }

    public function ensure(string $locale, ?int $userId): int
    {
        $collection = $this->collectionManager->getByKey(self::KEY, $locale);
        if (null !== $collection) {
            return (int) $collection->getId();
        }

        $created = $this->collectionManager->save([
            'key' => self::KEY,
            'title' => 'AI Created',
            'locale' => $locale,
            'type' => ['id' => 1],
        ], $userId);

        return (int) $created->getId();
    }
}
