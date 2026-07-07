<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\MediaMeta;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Sulu\Bundle\MediaBundle\Entity\FileVersionMeta;
use Sulu\Bundle\MediaBundle\Entity\Media;

/**
 * Finds raster images whose CURRENT file version is missing meta in at least
 * one locale. "Missing" = no FileVersionMeta row for the locale, or an empty
 * description (the description feeds the website's alt text).
 */
class MediaMetaFinder
{
    public const RASTER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param string[] $locales
     * @param int[] $excludeIds
     */
    public function count(array $locales, array $excludeIds = []): int
    {
        return (int) $this->createQueryBuilder($locales, true, $excludeIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param string[] $locales
     * @param int[] $excludeIds
     *
     * @return int[]
     */
    public function findIds(array $locales, int $limit, array $excludeIds = []): array
    {
        $ids = $this->createQueryBuilder($locales, false, $excludeIds)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return \array_map(\intval(...), $ids);
    }

    /**
     * Public so the DQL shape is unit-testable without a database.
     *
     * @param string[] $locales
     * @param int[] $excludeIds
     */
    public function createQueryBuilder(array $locales, bool $count, array $excludeIds = []): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select($count ? 'COUNT(DISTINCT media.id)' : 'DISTINCT media.id')
            ->from(Media::class, 'media')
            ->join('media.files', 'file')
            ->join('file.fileVersions', 'fileVersion', Join::WITH, 'fileVersion.version = file.version')
            ->where('fileVersion.mimeType IN (:mimeTypes)')
            ->setParameter('mimeTypes', self::RASTER_MIME_TYPES);

        if (!$count) {
            $qb->orderBy('media.id', 'ASC');
        }

        $missing = [];
        foreach (\array_values($locales) as $i => $locale) {
            $missing[] = \sprintf(
                'NOT EXISTS ('
                . 'SELECT meta%1$d.id FROM %2$s meta%1$d'
                . ' WHERE meta%1$d.fileVersion = fileVersion'
                . ' AND meta%1$d.locale = :locale%1$d'
                . " AND meta%1\$d.description IS NOT NULL AND meta%1\$d.description <> ''"
                . ')',
                $i,
                FileVersionMeta::class
            );
            $qb->setParameter('locale' . $i, $locale);
        }
        if ([] !== $missing) {
            $qb->andWhere(\implode(' OR ', $missing));
        }

        if ([] !== $excludeIds) {
            $qb->andWhere('media.id NOT IN (:excludeIds)')
                ->setParameter('excludeIds', \array_values($excludeIds));
        }

        return $qb;
    }
}
