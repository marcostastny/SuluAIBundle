<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\SseStream;
use PHPUnit\Framework\TestCase;

class SseStreamTest extends TestCase
{
    public function testWritesEventFrame(): void
    {
        $frames = [];
        $stream = new SseStream(function (string $frame) use (&$frames): void {
            $frames[] = $frame;
        });

        $stream->event('delta', ['text' => 'Hi']);

        $this->assertSame(["event: delta\ndata: {\"text\":\"Hi\"}\n\n"], $frames);
    }

    public function testEncodesEmptyDataAsObject(): void
    {
        $frames = [];
        $stream = new SseStream(function (string $frame) use (&$frames): void {
            $frames[] = $frame;
        });

        $stream->event('reset', []);

        $this->assertSame(["event: reset\ndata: {}\n\n"], $frames);
    }
}
