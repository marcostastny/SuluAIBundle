<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Controller\Admin\AssistantController;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantAgent;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantContextBuilder;
use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationGate;
use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationValidator;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\QueryResultCollector;
use Marcostastny\SuluAIBundle\Service\Assistant\EditOpValidator;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AssistantControllerTest extends TestCase
{
    private QueryResultCollector $queryResultCollector;
    private PageCreationValidator $pageCreationValidator;

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

        return new AssistantController(
            $entityManager,
            $contextBuilder ?? $this->createMock(AssistantContextBuilder::class),
            $agent ?? $this->createMock(AssistantAgent::class),
            new EditOpValidator(),
            $securityChecker ?? $this->createMock(SecurityCheckerInterface::class),
            $dataQueryGate,
            $this->queryResultCollector,
            $pageCreationGate,
            $this->pageCreationValidator
        );
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
}
