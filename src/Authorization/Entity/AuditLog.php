<?php

declare(strict_types=1);

namespace App\Authorization\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Authorization\Repository\AuditLogRepository::class)]
#[ORM\Table(name: 'authorization_audit_log')]
#[ORM\HasLifecycleCallbacks]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $actorUuid = null;

    #[ORM\Column(type: 'string', length: 120)]
    private string $action;

    #[ORM\Column(type: 'string', length: 80)]
    private string $targetType;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $targetUuid = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $beforeData = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $afterData = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $action, string $targetType, ?string $targetUuid = null, ?string $actorUuid = null)
    {
        $this->uuid = UUID::v4();
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetUuid = $targetUuid;
        $this->actorUuid = $actorUuid;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getActorUuid(): ?string
    {
        return $this->actorUuid;
    }

    public function setActorUuid(?string $actorUuid): self
    {
        $this->actorUuid = $actorUuid;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): self
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getTargetUuid(): ?string
    {
        return $this->targetUuid;
    }

    public function setTargetUuid(?string $targetUuid): self
    {
        $this->targetUuid = $targetUuid;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getBeforeData(): ?array
    {
        return $this->beforeData;
    }

    /** @param array<string, mixed>|null $beforeData */
    public function setBeforeData(?array $beforeData): self
    {
        $this->beforeData = $beforeData;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getAfterData(): ?array
    {
        return $this->afterData;
    }

    /** @param array<string, mixed>|null $afterData */
    public function setAfterData(?array $afterData): self
    {
        $this->afterData = $afterData;

        return $this;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(?string $requestId): self
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function __toString(): string
    {
        return sprintf('%s:%s', $this->action, $this->targetUuid ?? 'unknown');
    }
}
