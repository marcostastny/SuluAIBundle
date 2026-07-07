<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\Creation;

use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Whether the current admin user may create pages via the assistant: Sulu's
 * own webspace ADD permission decides, so the assistant never offers page
 * creation to users who could not create pages in the page tree either.
 */
class PageCreationGate
{
    private const SECURITY_CONTEXT_PREFIX = 'sulu.webspaces.';

    public function __construct(
        private SecurityCheckerInterface $securityChecker,
        private WebspaceManagerInterface $webspaceManager,
    ) {
    }

    public function isAvailable(): bool
    {
        return [] !== $this->allowedWebspaceKeys();
    }

    /**
     * The webspace to create in when neither the parent target nor the page
     * context determine one — only unambiguous with exactly one allowed key.
     */
    public function soleAllowedWebspaceKey(): ?string
    {
        $keys = $this->allowedWebspaceKeys();

        return 1 === \count($keys) ? $keys[0] : null;
    }

    /**
     * @return list<string>
     */
    public function allowedWebspaceKeys(): array
    {
        try {
            $keys = [];
            foreach ($this->webspaceManager->getWebspaceCollection()->getWebspaces() as $webspace) {
                $key = (string) $webspace->getKey();
                if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT_PREFIX . $key, PermissionTypes::ADD)) {
                    $keys[] = $key;
                }
            }

            return $keys;
        } catch (\Throwable) {
            // Feeds /admin/config and tool availability — degrade, never break.
            return [];
        }
    }
}
