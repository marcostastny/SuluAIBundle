<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\Entity\UserRole;
use Sulu\Component\Security\Authorization\MaskConverterInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The security contexts the current admin user may EDIT — the same filter
 * Sulu's admin SearchController applies to its search results.
 */
class EditableSecurityContexts
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private MaskConverterInterface $maskConverter,
    ) {
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $contexts = [];

        /** @var UserRole $userRole */
        foreach ($user->getUserRoles() as $userRole) {
            foreach ($userRole->getRole()->getPermissions() as $permission) {
                $permissions = $this->maskConverter->convertPermissionsToArray($permission->getPermissions());
                if ($permissions[PermissionTypes::EDIT] ?? false) {
                    $contexts[] = $permission->getContext();
                }
            }
        }

        return \array_values(\array_unique($contexts));
    }
}
