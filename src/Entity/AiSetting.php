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
    public const SECURITY_CONTEXT_ASSISTANT = 'sulu_ai.assistant';
    public const SECURITY_CONTEXT_IMAGE_GENERATION = 'sulu_ai.image_generation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Serializer\Expose]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[Serializer\Expose]
    private ?string $apiUrl = null;

    // Not exposed: the raw key must never be serialized back to the browser.
    // The form reads apiKeySet (below) to know whether one is stored.
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[Serializer\Expose]
    private ?string $model = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Serializer\Expose]
    private ?bool $enabled = false;

    /**
     * @var array<int, array{label: string, modelId: string, supportsReference: bool, maxImages: int}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Serializer\Expose]
    private ?array $imageModels = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Serializer\Expose]
    private ?string $imageStylePrompt = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[Serializer\Expose]
    private ?string $agentName = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[Serializer\Expose]
    private ?string $personality = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Serializer\Expose]
    private ?string $customPrompt = null;

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

    /**
     * Write-only surrogate: lets the admin form show whether a key is stored
     * without ever sending the secret to the browser.
     */
    #[Serializer\VirtualProperty]
    #[Serializer\SerializedName('apiKeySet')]
    #[Serializer\Expose]
    public function hasApiKey(): bool
    {
        return '' !== (string) $this->apiKey;
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

    /**
     * Whether the settings are complete enough to call the API. The chat
     * completions features (meta, assistant) need a chat model; image
     * generation picks its model from the imageModels list instead, so it
     * passes $requireChatModel = false.
     */
    public function isConfigured(bool $requireChatModel = true): bool
    {
        if (!$this->isEnabled() || '' === (string) $this->apiUrl || '' === (string) $this->apiKey) {
            return false;
        }

        return !$requireChatModel || '' !== (string) $this->model;
    }

    public function setEnabled(?bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * @return array<int, array{label: string, modelId: string, supportsReference: bool, maxImages: int}>
     */
    public function getImageModels(): array
    {
        return $this->imageModels ?? [];
    }

    /**
     * @param array<int, array{label: string, modelId: string, supportsReference: bool, maxImages: int}>|null $imageModels
     */
    public function setImageModels(?array $imageModels): self
    {
        $this->imageModels = $imageModels;

        return $this;
    }

    public function getImageStylePrompt(): ?string
    {
        return $this->imageStylePrompt;
    }

    public function setImageStylePrompt(?string $imageStylePrompt): self
    {
        $this->imageStylePrompt = $imageStylePrompt;

        return $this;
    }

    public function getAgentName(): ?string
    {
        return $this->agentName;
    }

    public function setAgentName(?string $agentName): self
    {
        $this->agentName = $agentName;

        return $this;
    }

    public function getPersonality(): ?string
    {
        return $this->personality;
    }

    public function setPersonality(?string $personality): self
    {
        $this->personality = $personality;

        return $this;
    }

    public function getCustomPrompt(): ?string
    {
        return $this->customPrompt;
    }

    public function setCustomPrompt(?string $customPrompt): self
    {
        $this->customPrompt = $customPrompt;

        return $this;
    }
}
