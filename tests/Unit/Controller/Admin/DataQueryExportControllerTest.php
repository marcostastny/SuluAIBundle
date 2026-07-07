<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Marcostastny\SuluAIBundle\Controller\Admin\DataQueryExportController;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryRunner;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\SelectQueryValidator;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class DataQueryExportControllerTest extends TestCase
{
    private function controller(
        array $tables = ['fo_dynamics'],
        ?DataQueryRunner $runner = null,
        ?SecurityCheckerInterface $securityChecker = null
    ): DataQueryExportController {
        $gate = $this->createMock(DataQueryGate::class);
        $gate->method('tables')->willReturn($tables);

        if (null === $runner) {
            $runner = $this->createMock(DataQueryRunner::class);
            $runner->method('run')->willReturn([
                'columns' => ['id', 'email'],
                'rows' => [['1', 'a@b.c'], ['2', 'says "hi", twice']],
            ]);
        }

        return new DataQueryExportController(
            $securityChecker ?? $this->createMock(SecurityCheckerInterface::class),
            $gate,
            new SelectQueryValidator(),
            $runner
        );
    }

    private function jsonRequest(array $payload): Request
    {
        return new Request(content: (string) \json_encode($payload));
    }

    public function testDeniedWithoutPermission(): void
    {
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('checkPermission')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);

        $this->controller(['fo_dynamics'], null, $securityChecker)
            ->postAction($this->jsonRequest(['sql' => 'SELECT id FROM fo_dynamics']));
    }

    public function testStreamsCsvWithHeaderRowAndEscaping(): void
    {
        $response = $this->controller()->postAction($this->jsonRequest(['sql' => 'SELECT id, email FROM fo_dynamics']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $content = (string) $response->getContent();
        $this->assertStringContainsString('id,email', $content);
        $this->assertStringContainsString('a@b.c', $content);
        $this->assertStringContainsString('"says ""hi"", twice"', $content);
    }

    public function testInvalidSqlReturns400(): void
    {
        $response = $this->controller()->postAction($this->jsonRequest(['sql' => 'DELETE FROM fo_dynamics']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDisallowedTableReturns400EvenWithPermission(): void
    {
        $response = $this->controller()->postAction($this->jsonRequest(['sql' => 'SELECT * FROM se_users']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testEmptyAllowlistReturns400(): void
    {
        $response = $this->controller([])->postAction($this->jsonRequest(['sql' => 'SELECT id FROM fo_dynamics']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDatabaseErrorReturns400(): void
    {
        $runner = $this->createMock(DataQueryRunner::class);
        $runner->method('run')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller(['fo_dynamics'], $runner)
            ->postAction($this->jsonRequest(['sql' => 'SELECT id FROM fo_dynamics']));

        $this->assertSame(400, $response->getStatusCode());
    }
}
