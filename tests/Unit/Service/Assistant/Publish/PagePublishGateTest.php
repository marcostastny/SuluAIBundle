<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\Publish;

use Marcostastny\SuluAIBundle\Service\Assistant\Publish\PagePublishGate;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;

class PagePublishGateTest extends TestCase
{
    private function createGate(array $webspaceKeys, callable $hasPermission): PagePublishGate
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
                fn (string $context, string $permission): bool => PermissionTypes::LIVE === $permission && $hasPermission($context)
            );

        return new PagePublishGate($securityChecker, $webspaceManager);
    }

    public function testAvailableWhenUserMayPublishInAWebspace(): void
    {
        $gate = $this->createGate(['kulm'], fn (string $context) => 'sulu.webspaces.kulm' === $context);

        $this->assertTrue($gate->isAvailable());
        $this->assertTrue($gate->allowsWebspace('kulm'));
        $this->assertFalse($gate->allowsWebspace('other'));
    }

    public function testUnavailableWithoutLivePermission(): void
    {
        $gate = $this->createGate(['kulm'], fn () => false);

        $this->assertFalse($gate->isAvailable());
        $this->assertFalse($gate->allowsWebspace('kulm'));
    }

    public function testDegradesToUnavailableWhenWebspaceManagerThrows(): void
    {
        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willThrowException(new \RuntimeException('boom'));
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);

        $gate = new PagePublishGate($securityChecker, $webspaceManager);

        $this->assertFalse($gate->isAvailable());
        $this->assertFalse($gate->allowsWebspace('kulm'));
    }
}
