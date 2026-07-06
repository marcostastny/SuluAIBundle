<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

/**
 * Assistant tools whose availability depends on request state (permissions,
 * settings). Unavailable tools are hidden from the model entirely: the
 * ToolRegistry neither lists their definition nor dispatches to them.
 */
interface ConditionalToolInterface
{
    public function isAvailable(): bool;
}
