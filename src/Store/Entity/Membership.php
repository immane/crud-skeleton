<?php

declare(strict_types=1);

namespace App\Store\Entity;

use App\Core\Utils\UUID;
use App\Store\Repository\MembershipRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\Table(name: 'store_membership')]
#[ORM\UniqueConstraint(name: 'uniq_store_membership_store_user', columns: ['store_id', 'user_uuid'])]
#[ORM\Index(name: 'idx_store_membership_user_status', columns: ['user_uuid', 'status'])]
#[ORM\Index(name: 'IDX_A8168968B092A811', columns: ['store_id'])]
class Membership
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_CLERK = 'clerk';
    public const ROLE_FULFILLMENT = 'fulfillment';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Store::class)]
    #[ORM\JoinColumn(name: 'store_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Store $store;

    #[ORM\Column(name: 'user_uuid', type: 'string', length: 36)]
    private string $userUuid;

    #[ORM\Column(type: 'string', length: 30)]
    private string $role;

    #[ORM\Column(type: 'string', length: 30)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Store $store, string $userUuid, string $role)
    {
        $this->uuid = UUID::v4();
        $this->store = $store;
        $this->userUuid = $userUuid;
        $this->setRole($role);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getStore(): Store { return $this->store; }
    public function getUserUuid(): string { return $this->userUuid; }
    public function getRole(): string { return $this->role; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function setRole(string $role): self
    {
        if (!in_array($role, self::roles(), true)) {
            throw new \InvalidArgumentException('Invalid store membership role.');
        }
        $this->role = $role;
        return $this;
    }

    public function activate(): self { $this->status = self::STATUS_ACTIVE; return $this->touch(); }
    public function suspend(): self { $this->status = self::STATUS_SUSPENDED; return $this->touch(); }
    public function revoke(): self { $this->status = self::STATUS_REVOKED; return $this->touch(); }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_OWNER, self::ROLE_MANAGER, self::ROLE_CLERK, self::ROLE_FULFILLMENT];
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
