<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use CmsIg\Seal\EngineInterface;
use CmsIg\Seal\Search\Condition\Condition;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\Entity\UserRole;
use Sulu\Component\Security\Authorization\MaskConverterInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Queries the SEAL "admin" search index with the same permission filter as
 * Sulu's admin SearchController: only documents whose securityContext the
 * current user may EDIT are returned.
 */
class AdminIndexSearcher
{
    private const INDEX = 'admin';

    public function __construct(
        private EngineInterface $engine,
        private TokenStorageInterface $tokenStorage,
        private MaskConverterInterface $maskConverter,
    ) {
    }

    /**
     * @param list<string> $resourceKeys
     *
     * @return list<array<string, mixed>> raw index documents
     */
    public function search(string $query, array $resourceKeys, ?string $locale, int $limit): array
    {
        $securityContexts = $this->editSecurityContexts();
        if ([] === $securityContexts) {
            return [];
        }

        $search = $this->engine->createSearchBuilder(self::INDEX)
            ->addFilter(Condition::search($query))
            ->addFilter(Condition::in('resourceKey', $resourceKeys))
            ->addFilter(Condition::in('securityContext', $securityContexts))
            ->limit($limit);

        if (null !== $locale && '' !== $locale) {
            $search = $search->addFilter(Condition::equal('locale', $locale));
        }

        $documents = [];
        foreach ($search->getResult() as $document) {
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @return list<string>
     */
    private function editSecurityContexts(): array
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
