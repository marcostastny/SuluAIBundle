<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Persistence\Model\AuditableInterface;
use Sulu\Component\Persistence\Model\AuditableTrait;

/**
 * One assistant conversation of one admin user. Messages are stored as a
 * JSON list of {role, content, hidden, actions} — always loaded and saved
 * whole, never queried individually.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sulu_ai_chat_sessions')]
class ChatSession implements AuditableInterface
{
    use AuditableTrait;

    public const MAX_MESSAGES = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'userId', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $title = null;

    /**
     * @var array<int, array{role: string, content: string, hidden: bool, actions: array<int, array<string, mixed>>}>
     */
    #[ORM\Column(type: 'json')]
    private array $messages = [];

    public function __construct(User $user)
    {
        $this->user = $user;
        // AuditableTrait timestamps are non-nullable; Doctrine hydrates
        // without the constructor, so stored rows are unaffected.
        $this->created = new \DateTimeImmutable();
        $this->changed = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return array<int, array{role: string, content: string, hidden: bool, actions: array<int, array<string, mixed>>}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @param array<int, array{role: string, content: string, hidden: bool, actions: array<int, array<string, mixed>>}> $messages
     */
    public function setMessages(array $messages): self
    {
        $this->messages = \array_slice(\array_values($messages), -self::MAX_MESSAGES);

        return $this;
    }
}
