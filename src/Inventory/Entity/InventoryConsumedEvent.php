<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\InventoryConsumedEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryConsumedEventRepository::class)]
#[ORM\Table(name: 'inventory_consumed_event')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_consumed_event_id', columns: ['event_id'])]
class InventoryConsumedEvent
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'event_id', type: 'string', length: 36, unique: true)]
    private string $eventId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $topic;

    #[ORM\Column(name: 'aggregate_id', type: 'string', length: 64)]
    private string $aggregateId;

    #[ORM\Column(name: 'payload_hash', type: 'string', length: 64)]
    private string $payloadHash;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    public function __construct(string $eventId, string $topic, string $aggregateId, string $payloadHash)
    {
        $this->uuid = UUID::v4();
        $this->eventId = $eventId;
        $this->topic = $topic;
        $this->aggregateId = $aggregateId;
        $this->payloadHash = $payloadHash;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }
}
