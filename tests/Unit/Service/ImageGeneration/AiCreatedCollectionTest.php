<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\ImageGeneration;

use Marcostastny\SuluAIBundle\Service\ImageGeneration\AiCreatedCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Api\Collection;
use Sulu\Bundle\MediaBundle\Collection\Manager\CollectionManagerInterface;

class AiCreatedCollectionTest extends TestCase
{
    public function testReturnsExistingCollectionId(): void
    {
        $existing = $this->createMock(Collection::class);
        $existing->method('getId')->willReturn(42);

        $manager = $this->createMock(CollectionManagerInterface::class);
        $manager->method('getByKey')->with('ai_created', 'de')->willReturn($existing);
        $manager->expects($this->never())->method('save');

        $service = new AiCreatedCollection($manager);

        $this->assertSame(42, $service->ensure('de', 7));
    }

    public function testCreatesCollectionWhenMissing(): void
    {
        $created = $this->createMock(Collection::class);
        $created->method('getId')->willReturn(99);

        $manager = $this->createMock(CollectionManagerInterface::class);
        $manager->method('getByKey')->willReturn(null);
        $manager->expects($this->once())
            ->method('save')
            ->with(
                $this->callback(static function (array $data): bool {
                    return 'ai_created' === $data['key']
                        && 'AI Created' === $data['title']
                        && 'de' === $data['locale']
                        && 1 === $data['type']['id'];
                }),
                7
            )
            ->willReturn($created);

        $service = new AiCreatedCollection($manager);

        $this->assertSame(99, $service->ensure('de', 7));
    }
}
