<?php

declare(strict_types=1);

namespace App\Store\Entity;

use App\Core\Utils\UUID;
use App\Store\Repository\StoreConsumedEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StoreConsumedEventRepository::class)]
#[ORM\Table(name: 'store_consumed_event')]
#[ORM\UniqueConstraint(name: 'uniq_store_consumed_event_id', columns: ['event_id'])]
class StoreConsumedEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'event_id', type: 'string', length: 36, unique: true)]
    private string $eventId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $topic;

    #[ORM\Column(name: 'aggregate_id', type: 'string', length: 64)]
    private string $aggregateId;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    #[ORM\Column(name: 'payload_hash', type: 'string', length: 64)]
    private string $payloadHash;

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
    public function getEventId(): string { return $this->eventId; }
    public function getTopic(): string { return $this->topic; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getProcessedAt(): \DateTimeImmutable { return $this->processedAt; }
    public function getPayloadHash(): string { return $this->payloadHash; }
}
