<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\LlmsTxt;

use Marcostastny\SuluAIBundle\Service\LlmsTxt\LlmsTxtRenderer;
use PHPUnit\Framework\TestCase;

class LlmsTxtRendererTest extends TestCase
{
    public function testRendersFullDocument(): void
    {
        $markdown = (new LlmsTxtRenderer())->render(
            'Hotel Kulm',
            'Family-run hotel in Triesenberg.',
            "Check-in from 15:00.\nPets welcome.",
            [
                ['title' => 'Zimmer & Preise', 'url' => 'https://hotelkulm.li/de/zimmer', 'description' => 'Helle, ruhige Zimmer.'],
                ['title' => 'Impressum', 'url' => 'https://hotelkulm.li/de/impressum', 'description' => ''],
            ],
            ['en' => 'https://hotelkulm.li/en/llms.txt'],
        );

        $this->assertSame(<<<'MD'
            # Hotel Kulm

            > Family-run hotel in Triesenberg.

            Check-in from 15:00.
            Pets welcome.

            ## Pages

            - [Zimmer & Preise](https://hotelkulm.li/de/zimmer): Helle, ruhige Zimmer.
            - [Impressum](https://hotelkulm.li/de/impressum)

            ## Other languages

            - [en](https://hotelkulm.li/en/llms.txt)

            MD, $markdown);
    }

    public function testOmitsEmptyOptionalParts(): void
    {
        $markdown = (new LlmsTxtRenderer())->render('Hotel Kulm', null, '  ', [], []);

        $this->assertSame("# Hotel Kulm\n", $markdown);
    }

    public function testKeepsLinesAndLinkSyntaxIntact(): void
    {
        $markdown = (new LlmsTxtRenderer())->render(
            'Hotel Kulm',
            "Line one\nline two",
            null,
            [['title' => "Offers [new]\n2026", 'url' => 'https://hotelkulm.li/de/a b', 'description' => "Two\nlines"]],
            [],
        );

        $this->assertStringContainsString("> Line one line two\n", $markdown);
        $this->assertStringContainsString('- [Offers \[new\] 2026](https://hotelkulm.li/de/a%20b): Two lines', $markdown);
    }
}
