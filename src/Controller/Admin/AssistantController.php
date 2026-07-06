<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantAgent;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantContextBuilder;
use Marcostastny\SuluAIBundle\Service\Assistant\EditOpValidator;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AssistantController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AssistantContextBuilder $contextBuilder,
        private AssistantAgent $agent,
        private EditOpValidator $editOpValidator,
        private SecurityCheckerInterface $securityChecker,
    ) {
    }

    public function postAction(Request $request): Response
    {
        $this->securityChecker->checkPermission(AiSetting::SECURITY_CONTEXT_ASSISTANT, PermissionTypes::VIEW);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $data = [];
        }

        $context = \is_array($data['context'] ?? null) ? $data['context'] : [];
        $formData = \is_array($data['formData'] ?? null) ? $data['formData'] : [];
        $messages = \is_array($data['messages'] ?? null) ? $data['messages'] : [];

        $template = (string) ($context['template'] ?? '');
        $locale = (string) ($context['locale'] ?? '');

        if ([] === $messages) {
            return new JsonResponse(['message' => 'Missing messages.'], 400);
        }

        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([], ['id' => 'ASC']);
        if (!$setting || !$setting->isConfigured()) {
            return new JsonResponse(['message' => 'AI is not configured or not enabled.'], 400);
        }

        $hasPageContext = '' !== $template && '' !== $locale;
        $validateOps = null;

        if ($hasPageContext) {
            try {
                $built = $this->contextBuilder->build($template, $locale, $formData);
            } catch (\RuntimeException $e) {
                return new JsonResponse(['message' => $e->getMessage()], 400);
            }
            $systemPrompt = $built['systemPrompt'];
            $validateOps = fn (array $ops): array => $this->editOpValidator->validate($ops, $built['schema'], $formData);
        } else {
            $systemPrompt = $this->contextBuilder->buildGlobalPrompt();
        }

        $messages = \array_values(\array_filter(\array_map(
            static function ($message): ?array {
                if (!\is_array($message)) {
                    return null;
                }
                $role = (string) ($message['role'] ?? '');
                if (!\in_array($role, ['user', 'assistant'], true)) {
                    return null;
                }

                return ['role' => $role, 'content' => (string) ($message['content'] ?? '')];
            },
            $messages
        )));

        try {
            $result = $this->agent->run(
                (string) $setting->getApiUrl(),
                (string) $setting->getApiKey(),
                (string) $setting->getModel(),
                $systemPrompt,
                $messages,
                $validateOps
            );
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'AI request failed: ' . $e->getMessage()], 502);
        }

        return new JsonResponse($result);
    }
}
