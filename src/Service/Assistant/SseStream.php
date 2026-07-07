<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

/**
 * Writes server-sent-event frames. The writer callable is injectable so
 * controller tests can capture frames instead of echoing them.
 */
class SseStream
{
    /** @var callable(string): void */
    private $write;

    public function __construct(?callable $write = null)
    {
        $this->write = $write ?? static function (string $frame): void {
            echo $frame;
            if (\ob_get_level() > 0) {
                \ob_flush();
            }
            \flush();
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    public function event(string $type, array $data): void
    {
        $json = [] === $data ? '{}' : (string) \json_encode($data);
        ($this->write)(\sprintf("event: %s\ndata: %s\n\n", $type, $json));
    }
}
