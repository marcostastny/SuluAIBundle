<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\MediaMeta;

/**
 * The image preview needed as vision input could not be rendered — the media
 * is skipped (not errored): nothing is wrong with the run, just this file.
 */
class PreviewNotSupportedException extends \RuntimeException
{
}
