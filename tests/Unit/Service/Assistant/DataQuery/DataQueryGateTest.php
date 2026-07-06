<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\DataQuery;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

class DataQueryGateTest extends TestCase
{
    private function gate(?AiSetting $setting, bool $hasPermission = true): DataQueryGate
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')
            ->with(AiSetting::SECURITY_CONTEXT_DATA_QUERY, PermissionTypes::VIEW)
            ->willReturn($hasPermission);

        return new DataQueryGate($entityManager, $securityChecker);
    }

    private function setting(?string $tables): AiSetting
    {
        return (new AiSetting())->setDataQueryTables($tables);
    }

    public function testTablesParsesTrimsAndDeduplicates(): void
    {
        $gate = $this->gate($this->setting("fo_forms\n  fo_dynamics  \n\nfo_forms\n"));

        $this->assertSame(['fo_forms', 'fo_dynamics'], $gate->tables());
    }

    public function testTablesDropsInvalidIdentifiers(): void
    {
        $gate = $this->gate($this->setting("fo_dynamics\nbad-name\nusers; DROP TABLE x\ndb.table"));

        $this->assertSame(['fo_dynamics'], $gate->tables());
    }

    public function testTablesEmptyWithoutSetting(): void
    {
        $this->assertSame([], $this->gate(null)->tables());
        $this->assertSame([], $this->gate($this->setting(null))->tables());
    }

    public function testAvailableRequiresPermissionAndTables(): void
    {
        $this->assertTrue($this->gate($this->setting('fo_dynamics'))->isAvailable());
        $this->assertFalse($this->gate($this->setting('fo_dynamics'), false)->isAvailable());
        $this->assertFalse($this->gate($this->setting(null))->isAvailable());
    }
}
