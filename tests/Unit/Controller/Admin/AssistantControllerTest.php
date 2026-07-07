<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Controller\Admin\AssistantController;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AssistantControllerTest extends TestCase
{
    private QueryResultCollector $queryResultCollector;
    private PageCreationValidator $pageCreationValidator;
    private ChatSessionRepository $sessionRepository;
    private SessionTitleGenerator $titleGenerator;
    private TokenStorage $tokenStorage;

    /** @var list<string> */
    private array $sseFrames = [];

    private function controller(
        ?AiSetting $setting,
        ?AssistantContextBuilder $contextBuilder = null,
        ?AssistantAgent $agent = null,
        ?SecurityCheckerInterface $securityChecker = null,
        bool $dataQueryAvailable = false,
        bool $creationAvailable = false
    ): AssistantController {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $dataQueryGate = $this->createMock(DataQueryGate::class);
        $dataQueryGate->method('isAvailable')->willReturn($dataQueryAvailable);

        $pageCreationGate = $this->createMock(PageCreationGate::class);
        $pageCreationGate->method('isAvailable')->willReturn($creationAvailable);
        $this->pageCreationValidator = $this->createMock(PageCreationValidator::class);

        $this->queryResultCollector = new QueryResultCollector();
        $this->sessionRepository = $this->createMock(ChatSessionRepository::class);
        $this->titleGenerator = $this->createMock(SessionTitleGenerator::class);
        $this->tokenStorage = new TokenStorage();

        return new AssistantController(
            $entityManager,
            $contextBuilder ?? $this->createMock(AssistantContextBuilder::class),
            $agent ?? $this->createMock(AssistantAgent::class),
            new EditOpValidator(),
            $securityChecker ?? $this->createMock(SecurityCheckerInterface::class),
            $dataQueryGate,
            $this->queryResultCollector,
            $pageCreationGate,
            $this->pageCreationValidator,
            $this->sessionRepository,
            $this->titleGenerator,
            $this->tokenStorage,
            new NullLogger(),
            new SseStream(function (string $frame): void {
                $this->sseFrames[] = $frame;
            })
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

    private function enabledSetting(): AiSetting
    {
        $setting = new AiSetting();
        $setting->setEnabled(true);
        $setting->setApiUrl('https://api.test/v1');
        $setting->setApiKey('key');
        $setting->setModel('gpt-test');

        return $setting;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(array $payload): Request
    {
        return new Request(content: (string) \json_encode($payload));
    }

    public function testDeniedWithoutPermission(): void
    {
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('checkPermission')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);

        $this->controller($this->enabledSetting(), null, null, $securityChecker)
            ->postAction($this->jsonRequest(['messages' => [['role' => 'user', 'content' => 'hi']]]));
    }

    public function testMissingMessagesReturn400(): void
    {
        $response = $this->controller($this->enabledSetting())->postAction($this->jsonRequest([]));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testGlobalModeWithoutContextUsesGlobalPrompt(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->expects($this->never())->method('build');
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->expects($this->once())
            ->method('run')
            ->with(
                'https://api.test/v1',
                'key',
                'gpt-test',
                'global prompt',
                [['role' => 'user', 'content' => 'find the reservation form']],
                null
            )
            ->willReturn(['reply' => 'Searching…', 'actions' => []]);

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent)->postAction($this->jsonRequest([
            'messages' => [['role' => 'user', 'content' => 'find the reservation form']],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['reply' => 'Searching…', 'actions' => []],
            \json_decode((string) $response->getContent(), true)
        );
    }

    public function testPartialContextFallsBackToGlobalMode(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->expects($this->never())->method('build');
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'ok', 'actions' => []]);

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent)->postAction($this->jsonRequest([
            'context' => ['type' => 'page', 'template' => 'default'],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNotConfiguredReturns400(): void
    {
        $response = $this->controller(null)->postAction($this->jsonRequest([
            'context' => ['type' => 'page', 'template' => 'default', 'locale' => 'de'],
            'formData' => [],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSuccessfulTurnReturnsAgentResult(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('build')->willReturn(['systemPrompt' => 'sys', 'schema' => ['fields' => []]]);
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'Hello!', 'actions' => []]);

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent)->postAction($this->jsonRequest([
            'context' => ['type' => 'page', 'template' => 'default', 'locale' => 'de'],
            'formData' => ['title' => 'T'],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['reply' => 'Hello!', 'actions' => []],
            \json_decode((string) $response->getContent(), true)
        );
    }

    public function testSeoTabContextUsesSeoBuilderAndPassesTabs(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->expects($this->never())->method('build');
        $contextBuilder->expects($this->once())
            ->method('buildSeoTab')
            ->with('de', ['seo' => ['title' => 'Alt']], $this->anything(), ['content', 'seo'])
            ->willReturn(['systemPrompt' => 'seo prompt', 'schema' => ['fields' => []]]);
        $agent = $this->createMock(AssistantAgent::class);
        $agent->expects($this->once())
            ->method('run')
            ->with(
                'https://api.test/v1',
                'key',
                'gpt-test',
                'seo prompt',
                [['role' => 'user', 'content' => 'Meta-Titel verbessern']],
                $this->isInstanceOf(\Closure::class),
                ['current' => 'seo', 'available' => ['content', 'seo']]
            )
            ->willReturn(['reply' => 'ok', 'actions' => []]);

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent)->postAction($this->jsonRequest([
            'context' => ['type' => 'page', 'locale' => 'de', 'tab' => 'seo', 'availableTabs' => ['content', 'seo']],
            'formData' => ['seo' => ['title' => 'Alt']],
            'messages' => [['role' => 'user', 'content' => 'Meta-Titel verbessern']],
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testContentTabPassesTabsToAgent(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('build')
            ->with('default', 'de', ['template' => 'default'], $this->anything(), ['content', 'seo'])
            ->willReturn(['systemPrompt' => 'page prompt', 'schema' => ['fields' => []]]);
        $agent = $this->createMock(AssistantAgent::class);
        $agent->expects($this->once())
            ->method('run')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'page prompt',
                $this->anything(),
                $this->isInstanceOf(\Closure::class),
                ['current' => 'content', 'available' => ['content', 'seo']]
            )
            ->willReturn(['reply' => 'ok', 'actions' => []]);

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent)->postAction($this->jsonRequest([
            'context' => ['type' => 'page', 'template' => 'default', 'locale' => 'de', 'tab' => 'content', 'availableTabs' => ['content', 'seo']],
            'formData' => ['template' => 'default'],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnknownTabFallsBackToContent(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->expects($this->once())->method('build')->willReturn(['systemPrompt' => 'p', 'schema' => ['fields' => []]]);
        $contextBuilder->expects($this->never())->method('buildSeoTab');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'ok', 'actions' => []]);

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent)->postAction($this->jsonRequest([
            'context' => ['type' => 'page', 'template' => 'default', 'locale' => 'de', 'tab' => 'excerpt'],
            'formData' => [],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAgentFailureReturns502(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('build')->willReturn(['systemPrompt' => 'sys', 'schema' => ['fields' => []]]);
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willThrowException(new \RuntimeException('API returned status 500'));

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent)->postAction($this->jsonRequest([
            'context' => ['type' => 'page', 'template' => 'default', 'locale' => 'de'],
            'formData' => [],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));

        $this->assertSame(502, $response->getStatusCode());
    }

    public function testDataQueryAvailabilityIsPassedToTheGlobalPrompt(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->expects($this->once())
            ->method('buildGlobalPrompt')
            ->with($this->isInstanceOf(AiSetting::class), true)
            ->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'ok', 'actions' => []]);

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent, null, true)
            ->postAction($this->jsonRequest(['messages' => [['role' => 'user', 'content' => 'hi']]]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreationValidatorPassedToAgentWhenGateAllows(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $capturedValidator = null;
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturnCallback(function (...$args) use (&$capturedValidator): array {
            $capturedValidator = $args[7] ?? null;

            return ['reply' => 'ok', 'actions' => []];
        });

        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent, null, false, true);
        $this->pageCreationValidator->expects($this->once())
            ->method('validate')
            ->with(['title' => 'X'], null)
            ->willReturn(['errors' => ['nope']]);

        $response = $controller->postAction($this->jsonRequest(['messages' => [['role' => 'user', 'content' => 'hi']]]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(\Closure::class, $capturedValidator);
        $this->assertSame(['errors' => ['nope']], $capturedValidator(['title' => 'X']));
    }

    public function testCreationValidatorReceivesContextWebspace(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('build')->willReturn(['systemPrompt' => 'sys', 'schema' => ['fields' => []]]);
        $capturedValidator = null;
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturnCallback(function (...$args) use (&$capturedValidator): array {
            $capturedValidator = $args[7] ?? null;

            return ['reply' => 'ok', 'actions' => []];
        });

        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent, null, false, true);
        $this->pageCreationValidator->expects($this->once())
            ->method('validate')
            ->with($this->anything(), 'kulm')
            ->willReturn(['errors' => []]);

        $controller->postAction($this->jsonRequest([
            'context' => ['type' => 'page', 'template' => 'default', 'locale' => 'de', 'webspace' => 'kulm'],
            'formData' => [],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));

        $this->assertInstanceOf(\Closure::class, $capturedValidator);
        $capturedValidator([]);
    }

    public function testCreationDisabledWhenGateDenies(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $capturedValidator = 'unset';
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturnCallback(function (...$args) use (&$capturedValidator): array {
            $capturedValidator = $args[7] ?? null;

            return ['reply' => 'ok', 'actions' => []];
        });

        $this->controller($this->enabledSetting(), $contextBuilder, $agent)
            ->postAction($this->jsonRequest(['messages' => [['role' => 'user', 'content' => 'hi']]]));

        $this->assertNull($capturedValidator);
    }

    public function testUnknownSessionIdReturns404BeforeAgentRuns(): void
    {
        $agent = $this->createMock(AssistantAgent::class);
        $agent->expects($this->never())->method('run');
        $controller = $this->controller($this->enabledSetting(), null, $agent);
        $user = $this->authenticate();
        $this->sessionRepository->method('findOneForUser')->with(99, $user)->willReturn(null);

        $response = $controller->postAction($this->jsonRequest([
            'sessionId' => 99,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFirstTurnCreatesSessionWithTitle(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'Hallo', 'actions' => []]);
        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent);
        $user = $this->authenticate();

        $session = new ChatSession($user);
        $property = new \ReflectionProperty(ChatSession::class, 'id');
        $property->setValue($session, 7);
        $this->sessionRepository->expects($this->once())->method('createForUser')->with($user)->willReturn($session);
        $this->sessionRepository->expects($this->once())->method('save')
            ->with($session, $this->callback(static function (array $messages): bool {
                $last = \end($messages);

                return 'assistant' === $last['role']
                    && 'Hallo' === $last['content']
                    && !isset($messages[0]['actions'][0]['store'])
                    && !isset($messages[0]['actions'][0]['baseline'])
                    && 'proposeEdits' === $messages[0]['actions'][0]['type'];
            }));
        $this->titleGenerator->method('generate')->willReturn('Wellness Fragen');

        $response = $controller->postAction($this->jsonRequest([
            'messages' => [[
                'role' => 'user',
                'content' => 'hi',
                'actions' => [['type' => 'proposeEdits', 'store' => 'X', 'baseline' => 'Y', 'ops' => []]],
            ]],
        ]));
        $payload = \json_decode((string) $response->getContent(), true);

        $this->assertSame(7, $payload['sessionId']);
        $this->assertSame('Wellness Fragen', $payload['sessionTitle']);
    }

    public function testExistingSessionSavedWithoutNewTitle(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'ok', 'actions' => []]);
        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent);
        $user = $this->authenticate();

        $session = new ChatSession($user);
        $session->setTitle('Alt');
        $this->sessionRepository->method('findOneForUser')->willReturn($session);
        $this->sessionRepository->expects($this->once())->method('save');
        $this->sessionRepository->expects($this->never())->method('createForUser');
        $this->titleGenerator->expects($this->never())->method('generate');

        $response = $controller->postAction($this->jsonRequest([
            'sessionId' => 5,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));
        $payload = \json_decode((string) $response->getContent(), true);

        $this->assertSame('Alt', $payload['sessionTitle']);
    }

    public function testNoTokenSkipsPersistence(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'ok', 'actions' => []]);
        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent);
        $this->sessionRepository->expects($this->never())->method('createForUser');

        $response = $controller->postAction($this->jsonRequest([
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));
        $payload = \json_decode((string) $response->getContent(), true);

        $this->assertArrayNotHasKey('sessionId', $payload);
    }

    public function testPersistenceFailureStillReturnsReply(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'ok', 'actions' => []]);
        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent);
        $this->authenticate();
        $this->sessionRepository->method('createForUser')->willThrowException(new \RuntimeException('db down'));

        $response = $controller->postAction($this->jsonRequest([
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]));
        $payload = \json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $payload['reply']);
        $this->assertArrayNotHasKey('sessionId', $payload);
    }

    public function testCollectedQueryResultsAreAppendedToTheActions(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $queryResultAction = ['type' => 'queryResult', 'title' => 'Latest', 'columns' => ['id'], 'rows' => [['1']], 'rowCount' => 1, 'sql' => 'SELECT id FROM fo_dynamics'];
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturnCallback(function () use ($queryResultAction): array {
            // Simulates run_select_query recording a titled result mid-run.
            $this->queryResultCollector->add($queryResultAction);

            return ['reply' => 'Here you go.', 'actions' => []];
        });

        $response = $this->controller($this->enabledSetting(), $contextBuilder, $agent)
            ->postAction($this->jsonRequest(['messages' => [['role' => 'user', 'content' => 'latest reservations']]]));

        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertSame([$queryResultAction], $decoded['actions']);
    }

    public function testStreamActionReturns400WithoutMessages(): void
    {
        $response = $this->controller($this->enabledSetting())->streamAction($this->jsonRequest([]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    public function testStreamActionEmitsAgentEventsAndResultFrame(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturnCallback(
            function ($apiUrl, $apiKey, $model, $systemPrompt, $messages, $validateOps, $tabs, $validateCreation, $onEvent): array {
                $onEvent(['type' => 'status', 'tool' => 'search_content']);
                $onEvent(['type' => 'delta', 'text' => 'Hal']);
                $onEvent(['type' => 'delta', 'text' => 'lo']);

                return ['reply' => 'Hallo', 'actions' => []];
            }
        );
        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent);

        $response = $controller->streamAction($this->jsonRequest([
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]));

        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));

        $response->sendContent();
        $output = \implode('', $this->sseFrames);

        $this->assertStringContainsString("event: status\ndata: {\"tool\":\"search_content\"}", $output);
        $this->assertStringContainsString("event: delta\ndata: {\"text\":\"Hal\"}", $output);
        $this->assertStringContainsString("event: delta\ndata: {\"text\":\"lo\"}", $output);
        $this->assertStringContainsString('event: result', $output);
        $this->assertStringContainsString('"reply":"Hallo"', $output);
    }

    public function testStreamActionPersistsSessionIntoResultFrame(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willReturn(['reply' => 'Hallo', 'actions' => []]);
        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent);
        $user = $this->authenticate();

        $session = new ChatSession($user);
        $property = new \ReflectionProperty(ChatSession::class, 'id');
        $property->setValue($session, 7);
        $this->sessionRepository->method('createForUser')->willReturn($session);
        $this->titleGenerator->method('generate')->willReturn('Kurzer Titel');

        $controller->streamAction($this->jsonRequest([
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]))->sendContent();

        $output = \implode('', $this->sseFrames);
        $this->assertStringContainsString('"sessionId":7', $output);
        $this->assertStringContainsString('"sessionTitle":"Kurzer Titel"', $output);
    }

    public function testStreamActionEmitsErrorFrameWhenAgentThrows(): void
    {
        $contextBuilder = $this->createMock(AssistantContextBuilder::class);
        $contextBuilder->method('buildGlobalPrompt')->willReturn('global prompt');
        $agent = $this->createMock(AssistantAgent::class);
        $agent->method('run')->willThrowException(new \RuntimeException('boom'));
        $controller = $this->controller($this->enabledSetting(), $contextBuilder, $agent);

        $controller->streamAction($this->jsonRequest([
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]))->sendContent();

        $output = \implode('', $this->sseFrames);
        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('boom', $output);
    }
}
