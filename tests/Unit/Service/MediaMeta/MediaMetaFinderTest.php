<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\MediaMeta;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Marcostastny\SuluAIBundle\Service\MediaMeta\MediaMetaFinder;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\FileVersionMeta;
use Sulu\Bundle\MediaBundle\Entity\Media;

class MediaMetaFinderTest extends TestCase
{
    private function finder(): MediaMetaFinder
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($entityManager)
        );

        return new MediaMetaFinder($entityManager);
    }

    public function testQuerySelectsLatestRasterVersionsWithAnyMissingLocale(): void
    {
        $qb = $this->finder()->createQueryBuilder(['de', 'en'], false);
        $dql = $qb->getDQL();

        $this->assertStringContainsString('SELECT DISTINCT media.id', $dql);
        $this->assertStringContainsString(Media::class, $dql);
        // Only the CURRENT file version is inspected.
        $this->assertStringContainsString('fileVersion.version = file.version', $dql);
        // One NOT EXISTS per locale, OR-ed: any missing locale selects the media.
        $this->assertSame(2, \substr_count($dql, 'NOT EXISTS'));
        $this->assertStringContainsString(FileVersionMeta::class, $dql);
        $this->assertStringContainsString('ORDER BY media.id ASC', $dql);

        $this->assertSame(
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            $qb->getParameter('mimeTypes')->getValue()
        );
        $this->assertSame('de', $qb->getParameter('locale0')->getValue());
        $this->assertSame('en', $qb->getParameter('locale1')->getValue());
    }

    public function testCountVariantCountsDistinctMedia(): void
    {
        $dql = $this->finder()->createQueryBuilder(['de'], true)->getDQL();

        $this->assertStringContainsString('SELECT COUNT(DISTINCT media.id)', $dql);
        $this->assertStringNotContainsString('ORDER BY', $dql);
    }

    public function testExcludeIdsAreApplied(): void
    {
        $qb = $this->finder()->createQueryBuilder(['de'], false, [3, 9]);

        $this->assertStringContainsString('media.id NOT IN (:excludeIds)', $qb->getDQL());
        $this->assertSame([3, 9], $qb->getParameter('excludeIds')->getValue());
    }

    public function testNoExcludeIdsAddsNoClause(): void
    {
        $qb = $this->finder()->createQueryBuilder(['de'], false);

        $this->assertStringNotContainsString('excludeIds', $qb->getDQL());
    }
}
