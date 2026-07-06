<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\PersonalityCatalog;
use PHPUnit\Framework\TestCase;

class PersonalityCatalogTest extends TestCase
{
    public function testEveryCatalogKeyHasAnInstruction(): void
    {
        foreach (PersonalityCatalog::KEYS as $key) {
            $instruction = PersonalityCatalog::instruction($key);

            $this->assertIsString($instruction, $key);
            $this->assertNotSame('', $instruction, $key);
        }
    }

    public function testFormalInstructionMentionsFormalGermanAddress(): void
    {
        $this->assertStringContainsString('"Sie"', (string) PersonalityCatalog::instruction('formal'));
    }

    public function testUnknownKeysResolveToNeutral(): void
    {
        $this->assertNull(PersonalityCatalog::instruction(null));
        $this->assertNull(PersonalityCatalog::instruction(''));
        $this->assertNull(PersonalityCatalog::instruction('neutral'));
        $this->assertNull(PersonalityCatalog::instruction('sarcastic'));
    }
}
