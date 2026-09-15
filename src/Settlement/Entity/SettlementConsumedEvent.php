<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Core\Utils\UUID;
use App\Settlement\Repository\SettlementConsumedEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettlementConsumedEventRepository::class)]
#[ORM\Table(name: 'settlement_consumed_event')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_consumed_event_id', columns: ['event_id'])]
class SettlementConsumedEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'event_id', type: 'string', length: 64, unique: true)]
    private string $eventId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $topic;

    #[ORM\Column(name: 'source_aggregate_type', type: 'string', length: 80)]
    private string $sourceAggregateType;

    #[ORM\Column(name: 'source_aggregate_id', type: 'string', length: 64)]
    private string $sourceAggregateId;

    #[ORM\Column(name: 'payload_hash', type: 'string', length: 64)]
    private string $payloadHash;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    public function __construct(
        string $eventId,
        string $topic,
        string $sourceAggregateType,
        string $sourceAggregateId,
        string $payloadHash,
    ) {
        $this->uuid = UUID::v4();
        $this->eventId = $eventId;
        $this->topic = $topic;
        $this->sourceAggregateType = $sourceAggregateType;
        $this->sourceAggregateId = $sourceAggregateId;
        $this->payloadHash = $payloadHash;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getEventId(): string { return $this->eventId; }
    public function getTopic(): string { return $this->topic; }
    public function getSourceAggregateType(): string { return $this->sourceAggregateType; }
    public function getSourceAggregateId(): string { return $this->sourceAggregateId; }
    public function getPayloadHash(): string { return $this->payloadHash; }
    public function getProcessedAt(): \DateTimeImmutable { return $this->processedAt; }
}
