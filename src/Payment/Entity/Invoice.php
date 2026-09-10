<?php

declare(strict_types=1);

namespace App\Payment\Entity;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Payment\Repository\InvoiceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'payment_invoice')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_payment_invoice_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_invoice_out_trade_no', columns: ['out_trade_no'])]
#[ORM\Index(name: 'idx_payment_invoice_source_status', columns: ['source_type', 'source_id', 'status'])]
#[ORM\Index(name: 'idx_payment_invoice_source_scene', columns: ['source_type', 'source_id', 'scene'])]
#[ORM\Index(name: 'idx_payment_invoice_payment_transaction', columns: ['payment', 'transaction_id'])]
class Invoice
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAYING = 'paying';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PARTIAL_REFUNDED = 'partial_refunded';
    public const STATUS_REFUNDED = 'refunded';

    public const SCENE_ORDER = 'order';
    public const SCENE_DEPOSIT = 'deposit';
    public const SCENE_WALLET_TOPUP = 'wallet_topup';

    public const PAYMENT_MOCK = 'mock';
    public const PAYMENT_WALLET = 'wallet';
    public const PAYMENT_WECHAT = 'wechat';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'out_trade_no', type: 'string', length: 64, unique: true)]
    private string $outTradeNo;

    #[ORM\Column(name: 'transaction_id', type: 'string', length: 128, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(name: 'source_type', type: 'string', length: 50)]
    private string $sourceType = '';

    #[ORM\Column(name: 'source_id', type: 'string', length: 64)]
    private string $sourceId = '';

    #[ORM\Column(type: 'string', length: 50)]
    private string $scene = self::SCENE_ORDER;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $payment = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $gateway = null;

    #[ORM\Column(name: 'trade_type', type: 'string', length: 50, nullable: true)]
    private ?string $tradeType = null;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $amount = 0;

    #[ORM\Column(name: 'refunded_amount', type: 'bigint', options: ['default' => 0])]
    private int $refundedAmount = 0;

    #[ORM\Column(type: 'string', length: 32, options: ['default' => 'CNY'])]
    private string $currency = 'CNY';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'payer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $payer = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'extra_data', type: 'json', nullable: true)]
    private ?array $extraData = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'paid_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'refunded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->outTradeNo = self::generateOutTradeNo();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s %.2f %s', $this->outTradeNo, $this->amount / 100, $this->currency);
    }

    public static function generateOutTradeNo(): string
    {
        return 'PAY' . (new \DateTimeImmutable())->format('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getOutTradeNo(): string { return $this->outTradeNo; }
    public function setOutTradeNo(string $outTradeNo): self { $this->outTradeNo = $outTradeNo; $this->touch(); return $this; }
    public function getTransactionId(): ?string { return $this->transactionId; }
    public function setTransactionId(?string $transactionId): self { $this->transactionId = $transactionId; $this->touch(); return $this; }
    public function getSourceType(): string { return $this->sourceType; }
    public function setSourceType(string $sourceType): self { $this->sourceType = $sourceType; $this->touch(); return $this; }
    public function getSourceId(): string { return $this->sourceId; }
    public function setSourceId(string $sourceId): self { $this->sourceId = $sourceId; $this->touch(); return $this; }
    public function getScene(): string { return $this->scene; }
    public function setScene(string $scene): self { $this->scene = $scene; $this->touch(); return $this; }
    public function getPayment(): ?string { return $this->payment; }
    public function setPayment(?string $payment): self { $this->payment = $payment; $this->touch(); return $this; }
    public function getGateway(): ?string { return $this->gateway; }
    public function setGateway(?string $gateway): self { $this->gateway = $gateway; $this->touch(); return $this; }
    public function getTradeType(): ?string { return $this->tradeType; }
    public function setTradeType(?string $tradeType): self { $this->tradeType = $tradeType; $this->touch(); return $this; }
    public function getStatus(): string { return $this->status; }
    /** @internal Used only by Symfony Workflow marking store. */
    public function setStatus(string $status): self { $this->status = $status; $this->touch(); return $this; }
    public function getAmount(): int { return $this->amount; }
    public function getAmountAsFloat(): float { return $this->amount / 100; }
    public function setAmount(int $amount): self { $this->amount = $amount; $this->touch(); return $this; }
    public function getRefundedAmount(): int { return $this->refundedAmount; }
    public function setRefundedAmount(int $refundedAmount): self { $this->refundedAmount = $refundedAmount; $this->touch(); return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); $this->touch(); return $this; }
    public function getPayer(): ?User { return $this->payer; }
    public function setPayer(?User $payer): self { $this->payer = $payer; $this->touch(); return $this; }
    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $subject): self { $this->subject = $subject; $this->touch(); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; $this->touch(); return $this; }
    /** @return array<string, mixed>|null */
    public function getExtraData(): ?array { return $this->extraData; }
    /** @param array<string, mixed>|null $extraData */
    public function setExtraData(?array $extraData): self { $this->extraData = $extraData; $this->touch(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): self { $this->paidAt = $paidAt; $this->touch(); return $this; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): self { $this->cancelledAt = $cancelledAt; $this->touch(); return $this; }
    public function getRefundedAt(): ?\DateTimeImmutable { return $this->refundedAt; }
    public function setRefundedAt(?\DateTimeImmutable $refundedAt): self { $this->refundedAt = $refundedAt; $this->touch(); return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** @param array<string, mixed> $data */
    public function appendExtraData(string $key, array $data): self
    {
        $extraData = $this->extraData ?? [];
        $extraData[$key] = $data;
        $this->extraData = $extraData;
        $this->touch();
        return $this;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
