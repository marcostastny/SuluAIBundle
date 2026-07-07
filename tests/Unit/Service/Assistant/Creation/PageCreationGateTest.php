<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\Creation;

use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationGate;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;

class PageCreationGateTest extends TestCase
{
    private function createGate(array $webspaceKeys, callable $hasPermission): PageCreationGate
    {
        $webspaces = [];
        foreach ($webspaceKeys as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')
            ->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')
            ->willReturnCallback(
                fn (string $context, string $permission): bool => PermissionTypes::ADD === $permission && $hasPermission($context)
            );

        return new PageCreationGate($securityChecker, $webspaceManager);
    }

    public function testAvailableWhenUserMayAddInAWebspace(): void
    {
        $gate = $this->createGate(['kulm'], fn (string $context) => 'sulu.webspaces.kulm' === $context);

        $this->assertTrue($gate->isAvailable());
        $this->assertSame('kulm', $gate->soleAllowedWebspaceKey());
        $this->assertSame(['kulm'], $gate->allowedWebspaceKeys());
    }

    public function testUnavailableWithoutAddPermission(): void
    {
        $gate = $this->createGate(['kulm'], fn () => false);

        $this->assertFalse($gate->isAvailable());
        $this->assertNull($gate->soleAllowedWebspaceKey());
    }

    public function testNoSoleKeyWithTwoAllowedWebspaces(): void
    {
        $gate = $this->createGate(['kulm', 'other'], fn () => true);

        $this->assertTrue($gate->isAvailable());
        $this->assertNull($gate->soleAllowedWebspaceKey());
    }

    public function testDegradesToUnavailableWhenWebspaceManagerThrows(): void
    {
        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willThrowException(new \RuntimeException('boom'));
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);

        $gate = new PageCreationGate($securityChecker, $webspaceManager);

        $this->assertFalse($gate->isAvailable());
    }
}
