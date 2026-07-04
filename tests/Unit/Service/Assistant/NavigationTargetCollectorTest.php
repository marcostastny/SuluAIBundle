<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;
use PHPUnit\Framework\TestCase;

class NavigationTargetCollectorTest extends TestCase
{
    private function target(string $type = 'pages', string $id = '1', string $locale = 'de'): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'locale' => $locale,
            'title' => 'Title',
            'view' => 'sulu_page.page_edit_form',
            'attributes' => ['id' => $id, 'locale' => $locale, 'webspace' => 'kulm'],
        ];
    }

    public function testAddAndGet(): void
    {
        $collector = new NavigationTargetCollector();
        $collector->add($this->target());

        $this->assertSame($this->target(), $collector->get('pages', '1', 'de'));
    }

    public function testGetUnknownReturnsNull(): void
    {
        $collector = new NavigationTargetCollector();
        $collector->add($this->target());

        $this->assertNull($collector->get('pages', '1', 'en'));
        $this->assertNull($collector->get('pages', '2', 'de'));
        $this->assertNull($collector->get('snippets', '1', 'de'));
    }

    public function testResetForgetsTargets(): void
    {
        $collector = new NavigationTargetCollector();
        $collector->add($this->target());
        $collector->reset();

        $this->assertNull($collector->get('pages', '1', 'de'));
    }
}
