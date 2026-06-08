<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Sulu\Component\Persistence\Model\AuditableInterface;
use Sulu\Component\Persistence\Model\AuditableTrait;

#[ORM\Entity]
#[ORM\Table(name: 'sulu_ai_settings')]
#[Serializer\ExclusionPolicy('all')]
class AiSetting implements AuditableInterface
{
    use AuditableTrait;

    public const RESOURCE_KEY = 'ai_settings';
    public const FORM_KEY = 'ai_settings';
    public const SECURITY_CONTEXT = 'sulu_ai.settings';
    public const SECURITY_CONTEXT_GENERATION = 'sulu_ai.meta_generation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Serializer\Expose]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[Serializer\Expose]
    private ?string $apiUrl = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[Serializer\Expose]
    private ?string $apiKey = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[Serializer\Expose]
    private ?string $model = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Serializer\Expose]
    private ?bool $enabled = false;

    public function __construct()
    {
        // Sulu 3's AuditableTrait timestamps are non-nullable; Doctrine hydrates
        // entities without invoking the constructor, so stored rows are unaffected.
        $this->created = new \DateTimeImmutable();
        $this->changed = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApiUrl(): ?string
    {
        return $this->apiUrl;
    }

    public function setApiUrl(?string $apiUrl): self
    {
        $this->apiUrl = $apiUrl;

        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): self
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->enabled;
    }

    public function setEnabled(?bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}
