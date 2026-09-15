<?php

declare(strict_types=1);

namespace App\Wallet\Entity;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Wallet\Repository\WalletRepository::class)]
#[ORM\Table(name: 'wallet')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_wallet_user_currency', columns: ['user_id', 'currency'])]
class Wallet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Unit of account code. Plain ISO currency (e.g. 'CNY') identifies the
     * default balance wallet; extended codes (e.g. 'CNY.ESCROW') identify
     * category accounts such as escrow or commission.
     */
    #[ORM\Column(type: 'string', length: 32, options: ['default' => 'USD'])]
    private string $currency = 'USD';

    /** Total balance in minor units (cents). */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $balance = 0;

    /** Frozen amount in minor units (cents); available balance = balance - held. */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $held = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user, string $currency = 'USD')
    {
        $this->uuid = UUID::v4();
        $this->user = $user;
        $this->currency = strtoupper($currency);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        $username = $this->user?->getUsername() ?? 'N/A';
        return sprintf('#%d %s %.2f %s', $this->id ?? 0, $username, $this->balance / 100, $this->currency);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * The unit of account is immutable after persistence: changing it would
     * break transactions, vouchers, and balance verification. Only a new
     * (unpersisted) wallet may be assigned a currency.
     */
    public function setCurrency(string $currency): self
    {
        $currency = strtoupper($currency);
        if ($this->id !== null && $this->currency !== $currency) {
            throw new \LogicException('Wallet currency is immutable after creation.');
        }
        $this->currency = $currency;
        return $this;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function getBalanceAsFloat(): float
    {
        return $this->balance / 100;
    }

    public function getHeld(): int
    {
        return $this->held;
    }

    public function getAvailableBalance(): int
    {
        return $this->balance - $this->held;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->touch();
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;
        $this->touch();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
