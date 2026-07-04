<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

/**
 * Remembers the navigation targets produced by search tools during one
 * assistant request. propose_navigation may only reference collected targets,
 * so the model can never invent admin views or attributes.
 */
class NavigationTargetCollector
{
    /**
     * @var array<string, array{type: string, id: string, locale: string, title: string, view: string, attributes: array<string, mixed>}>
     */
    private array $targets = [];

    /**
     * @param array{type: string, id: string, locale: string, title: string, view: string, attributes: array<string, mixed>} $target
     */
    public function add(array $target): void
    {
        $this->targets[$this->key($target['type'], $target['id'], $target['locale'])] = $target;
    }

    /**
     * @return array{type: string, id: string, locale: string, title: string, view: string, attributes: array<string, mixed>}|null
     */
    public function get(string $type, string $id, string $locale): ?array
    {
        return $this->targets[$this->key($type, $id, $locale)] ?? null;
    }

    public function reset(): void
    {
        $this->targets = [];
    }

    private function key(string $type, string $id, string $locale): string
    {
        return $type . ':' . $id . ':' . $locale;
    }
}
