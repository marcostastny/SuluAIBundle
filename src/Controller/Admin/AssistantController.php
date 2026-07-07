<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantAgent;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantContextBuilder;
use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationGate;
use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationValidator;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\QueryResultCollector;
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
        private DataQueryGate $dataQueryGate,
        private QueryResultCollector $queryResultCollector,
        private PageCreationGate $pageCreationGate,
        private PageCreationValidator $pageCreationValidator,
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

        $tab = (string) ($context['tab'] ?? 'content');
        if (!\in_array($tab, ['content', 'seo'], true)) {
            $tab = 'content';
        }
        $availableTabs = [];
        foreach (\is_array($context['availableTabs'] ?? null) ? $context['availableTabs'] : [] as $availableTab) {
            if (\is_string($availableTab) && '' !== $availableTab) {
                $availableTabs[] = $availableTab;
            }
        }
        if (!\in_array($tab, $availableTabs, true)) {
            $availableTabs[] = $tab;
        }

        if ([] === $messages) {
            return new JsonResponse(['message' => 'Missing messages.'], 400);
        }

        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([], ['id' => 'ASC']);
        if (!$setting || !$setting->isConfigured()) {
            return new JsonResponse(['message' => 'AI is not configured or not enabled.'], 400);
        }

        $dataQueryAvailable = $this->dataQueryGate->isAvailable();

        $creationAvailable = $this->pageCreationGate->isAvailable();
        $contextWebspace = \is_string($context['webspace'] ?? null) && '' !== $context['webspace'] ? $context['webspace'] : null;
        $validateCreation = $creationAvailable
            ? fn (array $arguments): array => $this->pageCreationValidator->validate($arguments, $contextWebspace)
            : null;

        // The SEO tab has a fixed form (no template); the content tab needs one.
        $hasPageContext = '' !== $locale && ('seo' === $tab || '' !== $template);
        $validateOps = null;
        $tabs = null;

        if ($hasPageContext) {
            try {
                $built = 'seo' === $tab
                    ? $this->contextBuilder->buildSeoTab($locale, $formData, $setting, $availableTabs, $dataQueryAvailable, $creationAvailable)
                    : $this->contextBuilder->build($template, $locale, $formData, $setting, $availableTabs, $dataQueryAvailable, $creationAvailable);
            } catch (\RuntimeException $e) {
                return new JsonResponse(['message' => $e->getMessage()], 400);
            }
            $systemPrompt = $built['systemPrompt'];
            $validateOps = fn (array $ops): array => $this->editOpValidator->validate($ops, $built['schema'], $formData);
            $tabs = ['current' => $tab, 'available' => $availableTabs];
        } else {
            $systemPrompt = $this->contextBuilder->buildGlobalPrompt($setting, $dataQueryAvailable, $creationAvailable);
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

        $this->queryResultCollector->reset();

        try {
            $result = $this->agent->run(
                (string) $setting->getApiUrl(),
                (string) $setting->getApiKey(),
                (string) $setting->getModel(),
                $systemPrompt,
                $messages,
                $validateOps,
                $tabs,
                $validateCreation
            );
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'AI request failed: ' . $e->getMessage()], 502);
        }

        // Titled run_select_query results are recorded out-of-band while the
        // agent loop runs; surface them as cards alongside the terminal action.
        $result['actions'] = [...$result['actions'], ...$this->queryResultCollector->all()];

        return new JsonResponse($result);
    }
}
