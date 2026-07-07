<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\Assistant\SessionTitleGenerator;
use Marcostastny\SuluAIBundle\Service\OpenAiClient;
use PHPUnit\Framework\TestCase;

class SessionTitleGeneratorTest extends TestCase
{
    private function createSetting(): AiSetting
    {
        return (new AiSetting())->setApiUrl('https://api.test')->setApiKey('k')->setModel('gpt-5-mini')->setEnabled(true);
    }

    public function testGeneratesTitleFromCompletion(): void
    {
        $client = $this->createMock(OpenAiClient::class);
        $client->expects($this->once())->method('postJson')
            ->with('https://api.test', 'k', '/chat/completions', $this->callback(
                static fn (array $json): bool => 'gpt-5-mini' === $json['model'] && !isset($json['temperature'])
            ))
            ->willReturn(['choices' => [['message' => ['content' => '"Tischreservationen Mai"']]]]);

        $title = (new SessionTitleGenerator($client))->generate($this->createSetting(), 'Zeig mir die Reservationen', 'Hier sind sie...');

        $this->assertSame('Tischreservationen Mai', $title);
    }

    public function testFallsBackToTruncatedUserMessageOnFailure(): void
    {
        $client = $this->createMock(OpenAiClient::class);
        $client->method('postJson')->willThrowException(new \RuntimeException('down'));

        $title = (new SessionTitleGenerator($client))->generate($this->createSetting(), \str_repeat('a', 100), 'reply');

        $this->assertSame(\str_repeat('a', 40), $title);
    }

    public function testFallsBackWhenCompletionEmpty(): void
    {
        $client = $this->createMock(OpenAiClient::class);
        $client->method('postJson')->willReturn(['choices' => [['message' => ['content' => '  ']]]]);

        $title = (new SessionTitleGenerator($client))->generate($this->createSetting(), 'Kurze Frage', 'reply');

        $this->assertSame('Kurze Frage', $title);
    }

    public function testLongTitlesAreCapped(): void
    {
        $client = $this->createMock(OpenAiClient::class);
        $client->method('postJson')->willReturn(['choices' => [['message' => ['content' => \str_repeat('t', 100)]]]]);

        $title = (new SessionTitleGenerator($client))->generate($this->createSetting(), 'x', 'y');

        $this->assertSame(60, \mb_strlen($title));
    }
}
