<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\MediaMeta;

use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\FileVersionMeta;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;

/**
 * Persists generated media meta through MediaManager::save() (which creates
 * FileVersionMeta for locales that have none). Missing-mode is conservative:
 * description only when empty, title only when empty or still the upload
 * filename; override-mode writes both fields for every locale.
 */
class MediaMetaWriter
{
    public function __construct(private MediaManagerInterface $mediaManager)
    {
    }

    /**
     * @param array<string, array{title: string, description: string}> $generated
     *
     * @return array<string, array<string, string>> locale => fields written
     */
    public function write(Media $media, array $generated, bool $override, int $userId): array
    {
        $fileVersion = $this->latestFileVersion($media);
        $fileName = $fileVersion?->getName() ?? '';
        $metas = $this->metasByLocale($fileVersion);

        $written = [];
        foreach ($generated as $locale => $values) {
            $meta = $metas[$locale] ?? null;
            $currentTitle = \trim((string) $meta?->getTitle());
            $currentDescription = \trim((string) $meta?->getDescription());

            $fields = [];
            if ($override || '' === $currentTitle || $this->titleEqualsFilename($currentTitle, $fileName)) {
                $fields['title'] = $values['title'];
            }
            if ($override || '' === $currentDescription) {
                $fields['description'] = $values['description'];
            }

            if ([] === $fields) {
                continue;
            }

            // A locale without any meta row always gets the title included
            // ($currentTitle is empty then), so MediaManager never creates a
            // FileVersionMeta with a NULL title (non-nullable column).
            $this->mediaManager->save(null, \array_merge(
                ['id' => $media->getId(), 'locale' => $locale],
                $fields
            ), $userId);
            $written[$locale] = $fields;
        }

        return $written;
    }

    /**
     * @param string[] $locales
     *
     * @return array<string, array{title: string, description: string}>
     */
    public function existingMeta(Media $media, array $locales): array
    {
        $metas = $this->metasByLocale($this->latestFileVersion($media));

        $existing = [];
        foreach ($locales as $locale) {
            $meta = $metas[$locale] ?? null;
            $existing[$locale] = [
                'title' => \trim((string) $meta?->getTitle()),
                'description' => \trim((string) $meta?->getDescription()),
            ];
        }

        return $existing;
    }

    public function latestFileVersion(Media $media): ?FileVersion
    {
        return $media->getFiles()[0]?->getLatestFileVersion();
    }

    /**
     * @return array<string, FileVersionMeta>
     */
    private function metasByLocale(?FileVersion $fileVersion): array
    {
        $metas = [];
        foreach ($fileVersion?->getMeta() ?? [] as $meta) {
            $metas[$meta->getLocale()] = $meta;
        }

        return $metas;
    }

    private function titleEqualsFilename(string $title, string $fileName): bool
    {
        if ('' === $fileName) {
            return false;
        }

        $withoutExtension = \pathinfo($fileName, \PATHINFO_FILENAME);

        return 0 === \strcasecmp($title, $fileName) || 0 === \strcasecmp($title, $withoutExtension);
    }
}
