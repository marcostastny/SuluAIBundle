<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\ChatSession;
use Sulu\Bundle\SecurityBundle\Entity\User;

/**
 * All access to chat sessions goes through here so that every query is
 * scoped to the owning user and the per-user cap is enforced in one place.
 */
class ChatSessionRepository
{
    public const MAX_SESSIONS_PER_USER = 20;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<ChatSession> newest change first
     */
    public function findForUser(User $user): array
    {
        return \array_values($this->entityManager->getRepository(ChatSession::class)
            ->findBy(['user' => $user], ['changed' => 'DESC']));
    }

    public function findOneForUser(int $id, User $user): ?ChatSession
    {
        return $this->entityManager->getRepository(ChatSession::class)
            ->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * Creates (persists, does not flush) a new session and removes the
     * oldest sessions beyond the cap. The new, yet unflushed session counts
     * as the newest, so everything from the cap's offset in the stored list
     * goes.
     */
    public function createForUser(User $user): ChatSession
    {
        $session = new ChatSession($user);
        $this->entityManager->persist($session);

        $stale = $this->entityManager->getRepository(ChatSession::class)
            ->findBy(['user' => $user], ['changed' => 'DESC'], null, self::MAX_SESSIONS_PER_USER);
        foreach ($stale as $old) {
            $this->entityManager->remove($old);
        }

        return $session;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function save(ChatSession $session, array $messages): void
    {
        $session->setMessages($messages);
        $session->setChanged(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function remove(ChatSession $session): void
    {
        $this->entityManager->remove($session);
        $this->entityManager->flush();
    }
}
