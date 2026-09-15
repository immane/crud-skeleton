<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Identity\Repository\RefreshTokenRepository::class)]
#[ORM\Table(name: 'identity_refresh_token')]
#[ORM\Index(name: 'idx_refresh_token_hash', columns: ['refresh_token_hash'])]
#[ORM\Index(name: 'idx_refresh_token_user', columns: ['user_id'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 128)]
    private string $refreshTokenHash;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $jti = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'replaced_by_token_id', type: 'bigint', nullable: true)]
    private ?int $replacedByTokenId = null;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent = null;

    public function __construct(User $user, string $hash, \DateTimeImmutable $expiresAt, ?string $jti = null)
    {
        $this->uuid = UUID::v4();
        $this->user = $user;
        $this->refreshTokenHash = $hash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->jti = $jti;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRefreshTokenHash(): string
    {
        return $this->refreshTokenHash;
    }

    public function getJti(): ?string
    {
        return $this->jti;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return new \DateTimeImmutable() > $this->expiresAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setReplacedBy(?int $tokenId): void
    {
        $this->replacedByTokenId = $tokenId;
    }

    public function getReplacedBy(): ?int
    {
        return $this->replacedByTokenId;
    }

    public function setIpAddress(?string $ipAddress): void
    {
        $this->ipAddress = $ipAddress;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setUserAgent(?string $userAgent): void
    {
        $this->userAgent = $userAgent;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setIdForTest(int $id): void
    {
        $this->id = $id;
    }
}
