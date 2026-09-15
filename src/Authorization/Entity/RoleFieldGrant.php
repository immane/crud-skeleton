<?php

declare(strict_types=1);

namespace App\Authorization\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Authorization\Repository\RoleFieldGrantRepository::class)]
#[ORM\Table(name: 'authorization_role_field_grant')]
#[ORM\UniqueConstraint(name: 'uniq_authorization_role_field_grant', columns: ['role_id', 'resource', 'action'])]
#[ORM\HasLifecycleCallbacks]
class RoleFieldGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: false)]
    private Role $role;

    #[ORM\Column(type: 'string', length: 80)]
    private string $resource;

    #[ORM\Column(type: 'string', length: 60)]
    private string $action;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $fields = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @param list<string> $fields
     */
    public function __construct(Role $role, string $resource, string $action, array $fields)
    {
        $this->uuid = UUID::v4();
        $this->role = $role;
        $this->resource = $resource;
        $this->action = $action;
        $this->fields = array_values(array_unique($fields));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function setRole(Role $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function setResource(string $resource): self
    {
        $this->resource = $resource;

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

    /**
     * @return list<string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @param list<string> $fields
     */
    public function setFields(array $fields): self
    {
        $this->fields = array_values(array_unique($fields));

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

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function __toString(): string
    {
        return sprintf('%s:%s:%s', $this->role->getCode(), $this->resource, $this->action);
    }
}
