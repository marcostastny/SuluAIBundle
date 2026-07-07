<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\DataQuery;

/**
 * The message is fed back to the model as a tool error, so keep it
 * human-readable and actionable.
 */
class InvalidSelectQueryException extends \RuntimeException
{
}
