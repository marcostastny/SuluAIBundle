<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Entity\ChatSession;
use Marcostastny\SuluAIBundle\Repository\ChatSessionRepository;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\SecurityBundle\Entity\User;

class ChatSessionRepositoryTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private EntityRepository $doctrineRepository;
    private ChatSessionRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->doctrineRepository = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(ChatSession::class)->willReturn($this->doctrineRepository);
        $this->repository = new ChatSessionRepository($this->entityManager);
    }

    public function testFindForUserOrdersByChangedDesc(): void
    {
        $user = new User();
        $this->doctrineRepository->expects($this->once())->method('findBy')
            ->with(['user' => $user], ['changed' => 'DESC'])
            ->willReturn([]);

        $this->assertSame([], $this->repository->findForUser($user));
    }

    public function testFindOneForUserScopesByUser(): void
    {
        $user = new User();
        $session = new ChatSession($user);
        $this->doctrineRepository->expects($this->once())->method('findOneBy')
            ->with(['id' => 7, 'user' => $user])
            ->willReturn($session);

        $this->assertSame($session, $this->repository->findOneForUser(7, $user));
    }

    public function testCreateForUserPersistsAndPrunesBeyondCap(): void
    {
        $user = new User();
        $stale = new ChatSession($user);
        $this->doctrineRepository->expects($this->once())->method('findBy')
            ->with(['user' => $user], ['changed' => 'DESC'], null, 20)
            ->willReturn([$stale]);
        $this->entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(ChatSession::class));
        $this->entityManager->expects($this->once())->method('remove')->with($stale);

        $session = $this->repository->createForUser($user);

        $this->assertSame($user, $session->getUser());
    }

    public function testSaveSetsMessagesAndFlushes(): void
    {
        $session = new ChatSession(new User());
        $before = $session->getChanged();
        $this->entityManager->expects($this->once())->method('flush');

        $this->repository->save($session, [['role' => 'user', 'content' => 'x', 'hidden' => false, 'actions' => []]]);

        $this->assertCount(1, $session->getMessages());
        $this->assertGreaterThanOrEqual($before, $session->getChanged());
    }

    public function testRemoveDeletesAndFlushes(): void
    {
        $session = new ChatSession(new User());
        $this->entityManager->expects($this->once())->method('remove')->with($session);
        $this->entityManager->expects($this->once())->method('flush');

        $this->repository->remove($session);
    }
}
