<?php

declare(strict_types=1);

namespace App\Wallet\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Wallet\Repository\TransactionRepository::class)]
#[ORM\Table(name: 'wallet_transaction')]
#[ORM\HasLifecycleCallbacks]
class Transaction
{
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_FEE = 'fee';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_CREDIT_REVERSAL = 'credit_reversal';
    public const TYPE_DEBIT_REVERSAL = 'debit_reversal';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'from_wallet_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Wallet $fromWallet = null;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'to_wallet_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Wallet $toWallet = null;

    /** Amount in minor units (cents). */
    #[ORM\Column(type: 'bigint')]
    private int $amount;

    #[ORM\Column(type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'string', length: 64, nullable: true, unique: true)]
    private ?string $referenceId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $metadata = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(?string $uuid, int $amount, string $type)
    {
        $this->uuid = $uuid ?? UUID::v4();
        $this->amount = $amount;
        $this->setType($type);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('[%s] #%d %s %.2f', $this->type, $this->id ?? 0, $this->uuid, $this->amount / 100);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getFromWallet(): ?Wallet
    {
        return $this->fromWallet;
    }

    public function setFromWallet(?Wallet $fromWallet): self
    {
        $this->fromWallet = $fromWallet;
        return $this;
    }

    public function getToWallet(): ?Wallet
    {
        return $this->toWallet;
    }

    public function setToWallet(?Wallet $toWallet): self
    {
        $this->toWallet = $toWallet;
        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getAmountAsFloat(): float
    {
        return $this->amount / 100;
    }

    public function getType(): string
    {
        return $this->type;
    }

    private function setType(string $type): void
    {
        $allowed = [
            self::TYPE_DEPOSIT,
            self::TYPE_WITHDRAWAL,
            self::TYPE_TRANSFER,
            self::TYPE_FEE,
            self::TYPE_REFUND,
            self::TYPE_ADJUSTMENT,
            self::TYPE_CREDIT_REVERSAL,
            self::TYPE_DEBIT_REVERSAL,
        ];
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid transaction type: $type");
        }
        $this->type = $type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $allowed = [self::STATUS_PENDING, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_REVERSED];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid transaction status: $status");
        }
        $this->status = $status;
        return $this;
    }

    public function getReferenceId(): ?string
    {
        return $this->referenceId;
    }

    public function setReferenceId(?string $referenceId): self
    {
        $this->referenceId = $referenceId;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getMetadata(): ?string
    {
        return $this->metadata;
    }

    public function setMetadata(?string $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markCompleted(): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markFailed(): self
    {
        $this->status = self::STATUS_FAILED;
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
