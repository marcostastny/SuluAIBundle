<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Controller\Admin\AssistantController;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantAgent;
use Marcostastny\SuluAIBundle\Service\Assistant\AssistantContextBuilder;
use Marcostastny\SuluAIBundle\Service\Assistant\EditOpValidator;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\Request;

class AssistantControllerTest extends TestCase
{
    private function controller(
        ?AiSetting $setting,
        ?AssistantContextBuilder $contextBuilder = null,
        ?AssistantAgent $agent = null
    ): AssistantController {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new AssistantController(
            $entityManager,
            $contextBuilder ?? $this->createMock(AssistantContextBuilder::class),
            $agent ?? $this->createMock(AssistantAgent::class),
            new EditOpValidator(),
            $this->createMock(SecurityCheckerInterface::class)
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
}
