<?php

declare(strict_types=1);

namespace App\Trade\Entity;

use App\Trade\Repository\OrderStoreLifecycleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderStoreLifecycleRepository::class)]
#[ORM\Table(name: 'trade_order_store_lifecycle')]
#[ORM\UniqueConstraint(name: 'uniq_trade_order_store_lifecycle_uuid', columns: ['trade_order_uuid'])]
#[ORM\Index(name: 'idx_trade_order_store_lifecycle_store', columns: ['store_uuid'])]
class OrderStoreLifecycle
{
    public const ACCEPTANCE_PENDING = 'pending';
    public const ACCEPTANCE_ACCEPTED = 'accepted';
    public const ACCEPTANCE_REJECTED = 'rejected';

    public const FULFILLMENT_PENDING = 'pending';
    public const FULFILLMENT_FULFILLED = 'fulfilled';

    public const VERIFICATION_NOT_REQUIRED = 'not_required';
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'trade_order_uuid', type: 'string', length: 36, unique: true)]
    private string $tradeOrderUuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid;

    #[ORM\Column(name: 'store_order_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $storeOrderUuid = null;

    #[ORM\Column(name: 'acceptance_status', type: 'string', length: 20)]
    private string $acceptanceStatus = self::ACCEPTANCE_PENDING;

    #[ORM\Column(name: 'fulfillment_status', type: 'string', length: 20)]
    private string $fulfillmentStatus = self::FULFILLMENT_PENDING;

    #[ORM\Column(name: 'verification_status', type: 'string', length: 20)]
    private string $verificationStatus = self::VERIFICATION_NOT_REQUIRED;

    #[ORM\Column(name: 'rejection_code', type: 'string', length: 50, nullable: true)]
    private ?string $rejectionCode = null;

    #[ORM\Column(name: 'rejection_reason', type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $tradeOrderUuid, string $storeUuid, ?string $storeOrderUuid = null)
    {
        $this->tradeOrderUuid = $tradeOrderUuid;
        $this->storeUuid = $storeUuid;
        $this->storeOrderUuid = $storeOrderUuid;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTradeOrderUuid(): string { return $this->tradeOrderUuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getStoreOrderUuid(): ?string { return $this->storeOrderUuid; }
    public function setStoreOrderUuid(?string $storeOrderUuid): self { $this->storeOrderUuid = $storeOrderUuid; return $this->touch(); }
    public function getAcceptanceStatus(): string { return $this->acceptanceStatus; }
    public function setAcceptanceStatus(string $status): self { $this->acceptanceStatus = $status; return $this->touch(); }
    public function getFulfillmentStatus(): string { return $this->fulfillmentStatus; }
    public function setFulfillmentStatus(string $status): self { $this->fulfillmentStatus = $status; return $this->touch(); }
    public function getVerificationStatus(): string { return $this->verificationStatus; }
    public function setVerificationStatus(string $status): self { $this->verificationStatus = $status; return $this->touch(); }
    public function getRejectionCode(): ?string { return $this->rejectionCode; }
    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function setRejection(?string $code, ?string $reason): self { $this->rejectionCode = $code; $this->rejectionReason = $reason; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function markAccepted(?string $storeOrderUuid = null): self
    {
        $this->acceptanceStatus = self::ACCEPTANCE_ACCEPTED;
        if ($storeOrderUuid !== null) {
            $this->storeOrderUuid = $storeOrderUuid;
        }
        $this->rejectionCode = null;
        $this->rejectionReason = null;

        return $this->touch();
    }

    public function markRejected(?string $storeOrderUuid, ?string $code, ?string $reason): self
    {
        $this->acceptanceStatus = self::ACCEPTANCE_REJECTED;
        if ($storeOrderUuid !== null) {
            $this->storeOrderUuid = $storeOrderUuid;
        }
        $this->rejectionCode = $code;
        $this->rejectionReason = $reason;

        return $this->touch();
    }

    public function markFulfilled(?string $storeOrderUuid = null, bool $requiresVerification = false): self
    {
        $this->fulfillmentStatus = self::FULFILLMENT_FULFILLED;
        if ($storeOrderUuid !== null) {
            $this->storeOrderUuid = $storeOrderUuid;
        }
        // A verified event may arrive before the fulfilled event on an async transport.
        if ($this->verificationStatus !== self::VERIFICATION_VERIFIED) {
            $this->verificationStatus = $requiresVerification ? self::VERIFICATION_PENDING : self::VERIFICATION_NOT_REQUIRED;
        }

        return $this->touch();
    }

    public function markVerified(?string $storeOrderUuid = null): self
    {
        $this->verificationStatus = self::VERIFICATION_VERIFIED;
        if ($storeOrderUuid !== null) {
            $this->storeOrderUuid = $storeOrderUuid;
        }

        return $this->touch();
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
