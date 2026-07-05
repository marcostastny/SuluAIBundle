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

    public function testDeepNestingIsBoundedAndDoesNotRecurseUnbounded(): void
    {
        // Nest far beyond the depth guard; must return without exhausting the
        // stack, and the too-deep leaf must not be collected.
        $node = ['deep-leaf'];
        for ($i = 0; $i < 500; ++$i) {
            $node = ['child' => $node];
        }
        $node['title'] = 'shallow-leaf';

        $result = (new ContentTextFlattener())->flatten($node);

        $this->assertStringContainsString('shallow-leaf', $result);
        $this->assertStringNotContainsString('deep-leaf', $result);
    }
}
