<?php

declare(strict_types=1);

namespace App\Store\Entity;

use App\Core\Utils\UUID;
use App\Store\Repository\StoreOrderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StoreOrderRepository::class)]
#[ORM\Table(name: 'store_order')]
#[ORM\UniqueConstraint(name: 'uniq_store_order_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_store_order_trade_order_uuid', columns: ['trade_order_uuid'])]
#[ORM\Index(name: 'idx_store_order_store_status_created', columns: ['store_id', 'operational_status', 'created_at'])]
#[ORM\Index(name: 'idx_store_order_customer_created', columns: ['customer_user_uuid', 'created_at'])]
#[ORM\Index(name: 'idx_store_order_reservation_id', columns: ['reservation_id'])]
class StoreOrder
{
    public const STATUS_PENDING_VALIDATION = 'pending_validation';
    public const STATUS_AWAITING_INVENTORY = 'awaiting_inventory';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FULFILLMENT_PENDING = 'fulfillment_pending';
    public const STATUS_FULFILLING = 'fulfilling';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'trade_order_uuid', type: 'string', length: 36, unique: true)]
    private string $tradeOrderUuid;

    #[ORM\ManyToOne(targetEntity: Store::class)]
    #[ORM\JoinColumn(name: 'store_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Store $store;

    #[ORM\Column(name: 'store_code_snapshot', type: 'string', length: 50)]
    private string $storeCodeSnapshot;

    #[ORM\Column(name: 'store_name_snapshot', type: 'string', length: 255)]
    private string $storeNameSnapshot;

    #[ORM\Column(name: 'customer_user_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $customerUserUuid;

    #[ORM\Column(type: 'string', length: 10)]
    private string $currency;

    #[ORM\Column(name: 'total_amount', type: 'bigint')]
    private int $totalAmount;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'order_snapshot', type: 'json')]
    private array $orderSnapshot;

    #[ORM\Column(name: 'operational_status', type: 'string', length: 40)]
    private string $operationalStatus = self::STATUS_PENDING_VALIDATION;

    #[ORM\Column(name: 'rejection_code', type: 'string', length: 50, nullable: true)]
    private ?string $rejectionCode = null;

    #[ORM\Column(name: 'rejection_reason', type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(name: 'accepted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'rejected_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'fulfillment_data', type: 'json', nullable: true)]
    private ?array $fulfillmentData = null;

    #[ORM\Column(name: 'reservation_id', type: 'string', length: 64, nullable: true)]
    private ?string $reservationId = null;

    #[ORM\Column(name: 'verified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'verified_by', type: 'string', length: 36, nullable: true)]
    private ?string $verifiedBy = null;

    #[ORM\Column(name: 'verification_code', type: 'string', length: 64, nullable: true)]
    private ?string $verificationCode = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @param array<string, mixed> $orderSnapshot */
    public function __construct(Store $store, string $tradeOrderUuid, string $storeCodeSnapshot, string $storeNameSnapshot, ?string $customerUserUuid, string $currency, int $totalAmount, array $orderSnapshot)
    {
        $this->uuid = UUID::v4();
        $this->store = $store;
        $this->tradeOrderUuid = $tradeOrderUuid;
        $this->storeCodeSnapshot = $storeCodeSnapshot;
        $this->storeNameSnapshot = $storeNameSnapshot;
        $this->customerUserUuid = $customerUserUuid;
        $this->currency = strtoupper($currency);
        $this->totalAmount = $totalAmount;
        $this->orderSnapshot = $orderSnapshot;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getTradeOrderUuid(): string { return $this->tradeOrderUuid; }
    public function getStore(): Store { return $this->store; }
    public function getStoreCodeSnapshot(): string { return $this->storeCodeSnapshot; }
    public function getStoreNameSnapshot(): string { return $this->storeNameSnapshot; }
    public function getCustomerUserUuid(): ?string { return $this->customerUserUuid; }
    public function getCurrency(): string { return $this->currency; }
    public function getTotalAmount(): int { return $this->totalAmount; }
    /** @return array<string, mixed> */
    public function getOrderSnapshot(): array { return $this->orderSnapshot; }
    public function getOperationalStatus(): string { return $this->operationalStatus; }
    public function getRejectionCode(): ?string { return $this->rejectionCode; }
    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function getRejectedAt(): ?\DateTimeImmutable { return $this->rejectedAt; }
    /** @return array<string, mixed>|null */
    public function getFulfillmentData(): ?array { return $this->fulfillmentData; }
    public function getReservationId(): ?string { return $this->reservationId; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function getVerifiedBy(): ?string { return $this->verifiedBy; }
    public function getVerificationCode(): ?string { return $this->verificationCode; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function awaitInventory(string $reservationId): self { $this->operationalStatus = self::STATUS_AWAITING_INVENTORY; $this->reservationId = $reservationId; return $this->touch(); }
    public function accept(?string $reservationId = null): self { $this->operationalStatus = self::STATUS_ACCEPTED; $this->reservationId = $reservationId ?? $this->reservationId; $this->acceptedAt = new \DateTimeImmutable(); return $this->touch(); }
    public function reject(string $code, string $reason): self { $this->operationalStatus = self::STATUS_REJECTED; $this->rejectionCode = $code; $this->rejectionReason = $reason; $this->rejectedAt = new \DateTimeImmutable(); return $this->touch(); }
    /** @param array<string, mixed>|null $data */
    public function beginFulfillment(?array $data = null): self { $this->operationalStatus = self::STATUS_FULFILLING; $this->fulfillmentData = $data; return $this->touch(); }
    /** @param array<string, mixed>|null $data */
    public function fulfill(?array $data = null): self { $this->operationalStatus = self::STATUS_FULFILLED; $this->fulfillmentData = $data; return $this->touch(); }
    public function verify(string $verificationCode, ?string $verifiedBy = null): self { $this->verificationCode = $verificationCode; $this->verifiedBy = $verifiedBy; $this->verifiedAt = new \DateTimeImmutable(); return $this->touch(); }
    public function cancel(): self { $this->operationalStatus = self::STATUS_CANCELLED; return $this->touch(); }

    private function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
