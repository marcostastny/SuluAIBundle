<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service;

use Marcostastny\SuluAIBundle\Service\ContentTextFlattener;
use PHPUnit\Framework\TestCase;

class ContentTextFlattenerTest extends TestCase
{
    public function testFlattenCollectsStringsAndStripsHtml(): void
    {
        $flattener = new ContentTextFlattener();
        $content = [
            'title' => 'Welcome',
            'article' => '<p>Hello <strong>world</strong></p>',
            'id' => 'should-skip-id',
            'url' => '/should-skip-url',
            'blocks' => [
                ['type' => 'text', 'text' => 'Inner block'],
            ],
        ];

        $result = $flattener->flatten($content);

        $this->assertStringContainsString('Welcome', $result);
        $this->assertStringContainsString('Hello world', $result);
        $this->assertStringContainsString('Inner block', $result);
        $this->assertStringNotContainsString('should-skip-id', $result);
        $this->assertStringNotContainsString('should-skip-url', $result);
        $this->assertStringNotContainsString('<p>', $result);
    }

    public function testFlattenTruncatesToMaxLength(): void
    {
        $flattener = new ContentTextFlattener();
        $result = $flattener->flatten(['body' => str_repeat('a', 100)], 10);
        $this->assertSame(10, mb_strlen($result));
    }
}
