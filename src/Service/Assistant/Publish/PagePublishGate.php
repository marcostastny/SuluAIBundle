<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\Publish;

use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Whether the current admin user may publish pages via the assistant: Sulu's
 * own webspace LIVE permission decides, so the assistant never offers
 * publishing to users who could not publish in the page tree either.
 */
class PagePublishGate
{
    private const SECURITY_CONTEXT_PREFIX = 'sulu.webspaces.';

    public function __construct(
        private SecurityCheckerInterface $securityChecker,
        private WebspaceManagerInterface $webspaceManager,
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            foreach ($this->webspaceManager->getWebspaceCollection()->getWebspaces() as $webspace) {
                if ($this->allowsWebspace((string) $webspace->getKey())) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            // Feeds /admin/config and tool availability — degrade, never break.
            return false;
        }
    }

    public function allowsWebspace(string $key): bool
    {
        try {
            return $this->securityChecker->hasPermission(self::SECURITY_CONTEXT_PREFIX . $key, PermissionTypes::LIVE);
        } catch (\Throwable) {
            return false;
        }
    }
}
