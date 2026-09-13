<?php

declare(strict_types=1);

namespace App\Store\Entity;

use App\Core\Utils\UUID;
use App\Store\Repository\StoreRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StoreRepository::class)]
#[ORM\Table(name: 'store')]
#[ORM\UniqueConstraint(name: 'uniq_store_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_store_code', columns: ['code'])]
class Store
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CLOSED = 'closed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $code = '';

    #[ORM\Column(type: 'string', length: 255)]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 30)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'string', length: 64)]
    private string $timezone = 'UTC';

    #[ORM\Column(type: 'string', length: 32, options: ['default' => 'CNY'])]
    private string $currency = 'CNY';

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contact = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $address = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(?string $code = null, ?string $name = null, string $timezone = 'UTC')
    {
        $this->uuid = UUID::v4();
        $this->code = $code ?? '';
        $this->name = $name ?? '';
        $this->timezone = $timezone;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->code;
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getStatus(): string { return $this->status; }
    public function getTimezone(): string { return $this->timezone; }
    public function getCurrency(): string { return $this->currency; }
    /** @return array<string, mixed>|null */
    public function getContact(): ?array { return $this->contact; }
    /** @return array<string, mixed>|null */
    public function getAddress(): ?array { return $this->address; }
    /** @return array<string, mixed>|null */
    public function getSettings(): ?array { return $this->settings; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function setName(string $name): self { $this->name = $name; return $this->touch(); }
    public function setCode(string $code): self { $this->code = $code; return $this->touch(); }
    public function setTimezone(string $timezone): self { $this->timezone = $timezone; return $this->touch(); }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this->touch(); }
    /** @param array<string, mixed>|null $contact */
    public function setContact(?array $contact): self { $this->contact = $contact; return $this->touch(); }
    /** @param array<string, mixed>|null $address */
    public function setAddress(?array $address): self { $this->address = $address; return $this->touch(); }
    /** @param array<string, mixed>|null $settings */
    public function setSettings(?array $settings): self { $this->settings = $settings; return $this->touch(); }

    public function activate(): self { $this->status = self::STATUS_ACTIVE; return $this->touch(); }
    public function suspend(): self { $this->status = self::STATUS_SUSPENDED; return $this->touch(); }
    public function close(): self { $this->status = self::STATUS_CLOSED; return $this->touch(); }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
