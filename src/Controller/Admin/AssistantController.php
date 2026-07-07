<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Entity\ChatSession;
use Marcostastny\SuluAIBundle\Repository\ChatSessionRepository;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantAgent;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantContextBuilder;
use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationGate;
use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationValidator;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\QueryResultCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\EditOpValidator;
use Marcostastny\SuluAIBundle\Service\Assistant\SessionTitleGenerator;
use Marcostastny\SuluAIBundle\Service\Assistant\SseStream;
use Psr\Log\LoggerInterface;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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
        private ChatSessionRepository $sessionRepository,
        private SessionTitleGenerator $titleGenerator,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger,
        private SseStream $sseStream,
    ) {
    }

    public function postAction(Request $request): Response
    {
        $prepared = $this->prepare($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        try {
            $result = $this->runAgent($prepared, null);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'AI request failed: ' . $e->getMessage()], 502);
        }

        return new JsonResponse($this->persist($result, $prepared));
    }

    public function streamAction(Request $request): Response
    {
        $prepared = $this->prepare($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        // Long-running response: release the session lock so parallel admin
        // requests from the same browser are not blocked for the whole turn.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $response = new StreamedResponse(function () use ($prepared): void {
            try {
                $result = $this->runAgent($prepared, function (array $event): void {
                    $type = (string) $event['type'];
                    unset($event['type']);
                    $this->sseStream->event($type, $event);
                });
                $result = $this->persist($result, $prepared);
                $this->sseStream->event('result', $result);
            } catch (\Throwable $e) {
                // The 200 status is already on the wire; failures become frames.
                $this->sseStream->event('error', ['message' => 'AI request failed: ' . $e->getMessage()]);
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Parses and validates the request; everything the agent run and the
     * persistence step need is bundled into one array so postAction and
     * streamAction share the exact same pipeline.
     *
     * @return array{
     *     setting: AiSetting,
     *     systemPrompt: string,
     *     messages: list<array{role: string, content: string}>,
     *     rawMessages: array<int, mixed>,
     *     validateOps: callable|null,
     *     tabs: array{current: string, available: list<string>}|null,
     *     validateCreation: callable|null,
     *     session: ChatSession|null,
     *     user: User|null,
     * }|JsonResponse
     */
    private function prepare(Request $request): array|JsonResponse
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
        $rawMessages = $messages;
        $sessionId = \is_numeric($data['sessionId'] ?? null) ? (int) $data['sessionId'] : null;

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

        $user = $this->tokenStorage->getToken()?->getUser();
        $user = $user instanceof User ? $user : null;

        // Resolve the session before the agent runs: an invalid id must not
        // cost an AI round-trip. Foreign sessions look exactly like missing
        // ones.
        $session = null;
        if (null !== $sessionId) {
            $session = null !== $user ? $this->sessionRepository->findOneForUser($sessionId, $user) : null;
            if (null === $session) {
                return new JsonResponse(['message' => 'Session not found.'], 404);
            }
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

        return [
            'setting' => $setting,
            'systemPrompt' => $systemPrompt,
            'messages' => $messages,
            'rawMessages' => $rawMessages,
            'validateOps' => $validateOps,
            'tabs' => $tabs,
            'validateCreation' => $validateCreation,
            'session' => $session,
            'user' => $user,
        ];
    }

    /**
     * @param array<string, mixed> $prepared a non-error prepare() result
     * @param (callable(array<string, mixed>): void)|null $onEvent
     *
     * @return array{reply: string, actions: list<array<string, mixed>>}
     */
    private function runAgent(array $prepared, ?callable $onEvent): array
    {
        $this->queryResultCollector->reset();

        $setting = $prepared['setting'];
        $result = $this->agent->run(
            (string) $setting->getApiUrl(),
            (string) $setting->getApiKey(),
            (string) $setting->getModel(),
            $prepared['systemPrompt'],
            $prepared['messages'],
            $prepared['validateOps'],
            $prepared['tabs'],
            $prepared['validateCreation'],
            $onEvent
        );

        // Titled run_select_query results are recorded out-of-band while the
        // agent loop runs; surface them as cards alongside the terminal action.
        $result['actions'] = [...$result['actions'], ...$this->queryResultCollector->all()];

        return $result;
    }

    /**
     * @param array{reply: string, actions: list<array<string, mixed>>} $result
     * @param array<string, mixed> $prepared a non-error prepare() result
     *
     * @return array<string, mixed> the result enriched with session data
     */
    private function persist(array $result, array $prepared): array
    {
        $user = $prepared['user'];
        if (null === $user) {
            return $result;
        }

        $session = $prepared['session'];
        try {
            $storedMessages = $this->storedMessages($prepared['rawMessages'], $result);
            if (null === $session) {
                $session = $this->sessionRepository->createForUser($user);
                $session->setTitle($this->titleGenerator->generate(
                    $prepared['setting'],
                    $this->firstVisibleUserMessage($storedMessages),
                    (string) $result['reply']
                ));
            }
            $this->sessionRepository->save($session, $storedMessages);
            $result['sessionId'] = $session->getId();
            $result['sessionTitle'] = (string) $session->getTitle();
        } catch (\Throwable $e) {
            // The reply must reach the user even when persistence fails.
            $this->logger->error('SuluAI: chat session persistence failed: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $rawMessages
     * @param array{reply: string, actions: list<array<string, mixed>>} $result
     *
     * @return array<int, array{role: string, content: string, hidden: bool, actions: array<int, array<string, mixed>>}>
     */
    private function storedMessages(array $rawMessages, array $result): array
    {
        $stored = [];
        foreach ($rawMessages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            if (!\in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $stored[] = $this->sanitizeStoredMessage($message);
        }
        $stored[] = $this->sanitizeStoredMessage([
            'role' => 'assistant',
            'content' => (string) $result['reply'],
            'hidden' => false,
            'actions' => $result['actions'],
        ]);

        return $stored;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array{role: string, content: string, hidden: bool, actions: array<int, array<string, mixed>>}
     */
    private function sanitizeStoredMessage(array $message): array
    {
        $actions = [];
        foreach (\is_array($message['actions'] ?? null) ? $message['actions'] : [] as $action) {
            if (!\is_array($action)) {
                continue;
            }
            // Client-only fields: the form-store reference and diff baseline
            // are neither JSON-safe nor meaningful outside the live form.
            unset($action['store'], $action['baseline']);
            $actions[] = $action;
        }

        return [
            'role' => (string) $message['role'],
            'content' => (string) ($message['content'] ?? ''),
            'hidden' => (bool) ($message['hidden'] ?? false),
            'actions' => $actions,
        ];
    }

    /**
     * @param array<int, array{role: string, content: string, hidden: bool}> $storedMessages
     */
    private function firstVisibleUserMessage(array $storedMessages): string
    {
        foreach ($storedMessages as $message) {
            if ('user' === $message['role'] && !$message['hidden']) {
                return $message['content'];
            }
        }

        return '';
    }
}
