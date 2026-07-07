<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Marcostastny\SuluAIBundle\Controller\Admin\ChatSessionController;
use Marcostastny\SuluAIBundle\Entity\ChatSession;
use Marcostastny\SuluAIBundle\Repository\ChatSessionRepository;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class ChatSessionControllerTest extends TestCase
{
    private ChatSessionRepository $repository;
    private TokenStorage $tokenStorage;
    private ChatSessionController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ChatSessionRepository::class);
        $this->tokenStorage = new TokenStorage();
        $this->controller = new ChatSessionController(
            $this->repository,
            $this->createMock(SecurityCheckerInterface::class),
            $this->tokenStorage
        );
    }

    private function authenticate(): User
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->setToken($token);

        return $user;
    }

    public function testListReturnsSessions(): void
    {
        $user = $this->authenticate();
        $session = new ChatSession($user);
        $session->setTitle('Wellness');
        $this->repository->method('findForUser')->with($user)->willReturn([$session]);

        $payload = \json_decode((string) $this->controller->cgetAction()->getContent(), true);

        $this->assertSame('Wellness', $payload['sessions'][0]['title']);
        $this->assertArrayHasKey('changed', $payload['sessions'][0]);
    }

    public function testGetReturnsMessages(): void
    {
        $user = $this->authenticate();
        $session = new ChatSession($user);
        $session->setMessages([['role' => 'user', 'content' => 'hi', 'hidden' => false, 'actions' => []]]);
        $this->repository->method('findOneForUser')->with(3, $user)->willReturn($session);

        $payload = \json_decode((string) $this->controller->getAction(3)->getContent(), true);

        $this->assertSame('hi', $payload['messages'][0]['content']);
    }

    public function testGetUnknownSessionReturns404(): void
    {
        $this->authenticate();
        $this->repository->method('findOneForUser')->willReturn(null);

        $this->assertSame(404, $this->controller->getAction(3)->getStatusCode());
    }

    public function testDeleteRemovesOwnSession(): void
    {
        $user = $this->authenticate();
        $session = new ChatSession($user);
        $this->repository->method('findOneForUser')->with(3, $user)->willReturn($session);
        $this->repository->expects($this->once())->method('remove')->with($session);

        $this->assertSame(204, $this->controller->deleteAction(3)->getStatusCode());
    }

    public function testDeleteUnknownSessionReturns404(): void
    {
        $this->authenticate();
        $this->repository->method('findOneForUser')->willReturn(null);
        $this->repository->expects($this->never())->method('remove');

        $this->assertSame(404, $this->controller->deleteAction(3)->getStatusCode());
    }

    public function testNoUserReturns403(): void
    {
        $this->assertSame(403, $this->controller->cgetAction()->getStatusCode());
    }
}
