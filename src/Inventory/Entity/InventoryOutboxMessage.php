<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\InventoryOutboxMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryOutboxMessageRepository::class)]
#[ORM\Table(name: 'inventory_outbox_message')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_outbox_event_id', columns: ['event_id'])]
#[ORM\Index(name: 'idx_inventory_outbox_unpublished_available', columns: ['published_at', 'available_at', 'id'])]
class InventoryOutboxMessage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'event_id', type: 'string', length: 36, unique: true)]
    private string $eventId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $topic;

    #[ORM\Column(name: 'aggregate_type', type: 'string', length: 80)]
    private string $aggregateType;

    #[ORM\Column(name: 'aggregate_id', type: 'string', length: 64)]
    private string $aggregateId;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'available_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $topic, string $aggregateType, string $aggregateId, array $payload, ?\DateTimeImmutable $occurredAt = null)
    {
        $this->uuid = UUID::v4();
        $this->eventId = UUID::v4();
        $this->topic = $topic;
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->payload = $payload;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
        $this->availableAt = $this->occurredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    public function markPublished(): void
    {
        $this->publishedAt = new \DateTimeImmutable();
    }

    public function recordAttempt(?string $error, \DateTimeImmutable $availableAt): void
    {
        ++$this->attempts;
        $this->lastError = $error;
        $this->availableAt = $availableAt;
    }
}
