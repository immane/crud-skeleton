<?php

declare(strict_types=1);

namespace App\Payment\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Payment\Repository\InvoiceRefundAttemptRepository::class)]
#[ORM\Table(name: 'payment_invoice_refund_attempt')]
#[ORM\Index(name: 'idx_invoice_refund_attempt_invoice_status', columns: ['invoice_uuid', 'status'])]
class InvoiceRefundAttempt
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\Column(name: 'attempt_key', type: 'string', length: 64)]
    private string $attemptKey;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'invoice_uuid', type: 'string', length: 36)]
    private string $invoiceUuid;

    #[ORM\Column(name: 'requested_amount', type: 'bigint')]
    private int $requestedAmount;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PROCESSING;

    #[ORM\Column(name: 'gateway_refund_id', type: 'string', length: 128, nullable: true)]
    private ?string $gatewayRefundId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allocation = null;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed>|null $allocation */
    public function __construct(string $attemptKey, string $invoiceUuid, int $requestedAmount, string $reason, ?array $allocation = null)
    {
        $this->attemptKey = $attemptKey;
        $this->uuid = UUID::v4();
        $this->invoiceUuid = $invoiceUuid;
        $this->requestedAmount = $requestedAmount;
        $this->reason = $reason;
        $this->allocation = $allocation;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAttemptKey(): string { return $this->attemptKey; }
    public function getUuid(): string { return $this->uuid; }
    public function getInvoiceUuid(): string { return $this->invoiceUuid; }
    public function getRequestedAmount(): int { return $this->requestedAmount; }
    public function getStatus(): string { return $this->status; }
    public function getGatewayRefundId(): ?string { return $this->gatewayRefundId; }
    /** @return array<string, mixed>|null */
    public function getResult(): ?array { return $this->result; }
    public function getReason(): string { return $this->reason; }
    /** @return array<string, mixed>|null */
    public function getAllocation(): ?array { return $this->allocation; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @param array<string, mixed> $result */
    public function complete(?string $gatewayRefundId, array $result): void
    {
        $this->gatewayRefundId = $gatewayRefundId;
        $this->result = $result;
        $this->status = self::STATUS_COMPLETED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markUnknown(): void
    {
        $this->status = self::STATUS_UNKNOWN;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function resume(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
