<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Entity\ChatSession;
use Marcostastny\SuluAIBundle\Repository\ChatSessionRepository;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Chat-session history of the current admin user. Every lookup is scoped to
 * the authenticated user — sessions of other users are indistinguishable
 * from missing ones (404).
 */
class ChatSessionController
{
    public function __construct(
        private ChatSessionRepository $sessionRepository,
        private SecurityCheckerInterface $securityChecker,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function cgetAction(): Response
    {
        $this->securityChecker->checkPermission(AiSetting::SECURITY_CONTEXT_ASSISTANT, PermissionTypes::VIEW);
        $user = $this->currentUser();
        if (null === $user) {
            return new JsonResponse(['message' => 'No admin user.'], 403);
        }

        return new JsonResponse(['sessions' => \array_map(
            static fn (ChatSession $session): array => [
                'id' => $session->getId(),
                'title' => (string) $session->getTitle(),
                'changed' => $session->getChanged()->format(\DateTimeInterface::ATOM),
            ],
            $this->sessionRepository->findForUser($user)
        )]);
    }

    public function getAction(int $id): Response
    {
        $this->securityChecker->checkPermission(AiSetting::SECURITY_CONTEXT_ASSISTANT, PermissionTypes::VIEW);
        $user = $this->currentUser();
        if (null === $user) {
            return new JsonResponse(['message' => 'No admin user.'], 403);
        }

        $session = $this->sessionRepository->findOneForUser($id, $user);
        if (null === $session) {
            return new JsonResponse(['message' => 'Session not found.'], 404);
        }

        return new JsonResponse([
            'id' => $session->getId(),
            'title' => (string) $session->getTitle(),
            'messages' => $session->getMessages(),
        ]);
    }

    public function deleteAction(int $id): Response
    {
        $this->securityChecker->checkPermission(AiSetting::SECURITY_CONTEXT_ASSISTANT, PermissionTypes::VIEW);
        $user = $this->currentUser();
        if (null === $user) {
            return new JsonResponse(['message' => 'No admin user.'], 403);
        }

        $session = $this->sessionRepository->findOneForUser($id, $user);
        if (null === $session) {
            return new JsonResponse(['message' => 'Session not found.'], 404);
        }

        $this->sessionRepository->remove($session);

        return new Response('', 204);
    }

    private function currentUser(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }
}
