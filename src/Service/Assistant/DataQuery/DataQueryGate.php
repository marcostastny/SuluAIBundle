<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\DataQuery;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

/**
 * Decides whether the current user may use the read-only data-query tools and
 * provides the sanitized table allowlist. Availability requires BOTH the
 * sulu_ai.data_query permission and a non-empty allowlist in the AI settings.
 */
class DataQueryGate
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecurityCheckerInterface $securityChecker,
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            if (!$this->securityChecker->hasPermission(AiSetting::SECURITY_CONTEXT_DATA_QUERY, PermissionTypes::VIEW)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return [] !== $this->tables();
    }

    /**
     * The allowlisted table names, one per line in the settings textarea.
     * Only plain identifiers survive, so downstream consumers may embed the
     * names in SQL (quoted) without further escaping.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        try {
            $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([], ['id' => 'ASC']);
        } catch (\Throwable) {
            return [];
        }

        $tables = [];
        foreach (\explode("\n", (string) $setting?->getDataQueryTables()) as $line) {
            $table = \trim($line);
            if ('' === $table || 1 !== \preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            if (!\in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }
}
