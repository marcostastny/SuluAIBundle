<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Entity;

use Marcostastny\SuluAIBundle\Entity\ChatSession;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\SecurityBundle\Entity\User;

class ChatSessionTest extends TestCase
{
    public function testStoresUserTitleAndMessages(): void
    {
        $user = new User();
        $session = new ChatSession($user);
        $session->setTitle('Wellness Fragen');
        $session->setMessages([['role' => 'user', 'content' => 'Hi', 'hidden' => false, 'actions' => []]]);

        $this->assertSame($user, $session->getUser());
        $this->assertSame('Wellness Fragen', $session->getTitle());
        $this->assertCount(1, $session->getMessages());
        $this->assertNotNull($session->getCreated());
        $this->assertNotNull($session->getChanged());
    }

    public function testMessagesTrimmedToLastTwoHundred(): void
    {
        $session = new ChatSession(new User());
        $messages = [];
        for ($i = 0; $i < 250; ++$i) {
            $messages[] = ['role' => 'user', 'content' => 'm' . $i, 'hidden' => false, 'actions' => []];
        }
        $session->setMessages($messages);

        $this->assertCount(200, $session->getMessages());
        $this->assertSame('m249', $session->getMessages()[199]['content']);
        $this->assertSame('m50', $session->getMessages()[0]['content']);
    }
}
