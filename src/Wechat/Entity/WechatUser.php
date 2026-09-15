<?php

declare(strict_types=1);

namespace App\Wechat\Entity;

use App\Core\Entity\UuidIdentityTrait;
use App\Identity\Entity\User;
use App\Wechat\Repository\WechatUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WechatUserRepository::class)]
#[ORM\Table(name: 'wechat_user')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_user_openid', columns: ['openid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_user_user', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class WechatUser
{
    use UuidIdentityTrait;

    public const APP_TYPE_MINIAPP = 'miniapp';
    public const APP_TYPE_OFFICIAL = 'official';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 64)]
    private string $openid;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $unionid = null;

    #[ORM\Column(name: 'session_key', type: 'string', length: 64, nullable: true)]
    private ?string $sessionKey = null;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $avatar = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sex = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $province = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'app_type', type: 'string', length: 20)]
    private string $appType;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'raw_data', type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column(name: 'last_login_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastLoginAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user, string $openid, string $appType)
    {
        $this->initializeUuid();
        $this->user = $user;
        $this->openid = $openid;
        $this->appType = $appType;
        $this->lastLoginAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('WechatUser#%d %s %s', $this->id ?? 0, $this->appType, $this->openid);
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }

    public function getOpenid(): string { return $this->openid; }

    public function setOpenid(string $openid): self { $this->openid = $openid; $this->touch(); return $this; }

    public function getUnionid(): ?string { return $this->unionid; }

    public function setUnionid(?string $unionid): self { $this->unionid = $unionid; $this->touch(); return $this; }

    public function getSessionKey(): ?string { return $this->sessionKey; }

    public function setSessionKey(?string $sessionKey): self { $this->sessionKey = $sessionKey; $this->touch(); return $this; }

    public function getNickname(): ?string { return $this->nickname; }

    public function setNickname(?string $nickname): self { $this->nickname = $nickname; $this->touch(); return $this; }

    public function getAvatar(): ?string { return $this->avatar; }

    public function setAvatar(?string $avatar): self { $this->avatar = $avatar; $this->touch(); return $this; }

    public function getSex(): ?int { return $this->sex; }

    public function setSex(?int $sex): self { $this->sex = $sex; $this->touch(); return $this; }

    public function getProvince(): ?string { return $this->province; }

    public function setProvince(?string $province): self { $this->province = $province; $this->touch(); return $this; }

    public function getCity(): ?string { return $this->city; }

    public function setCity(?string $city): self { $this->city = $city; $this->touch(); return $this; }

    public function getCountry(): ?string { return $this->country; }

    public function setCountry(?string $country): self { $this->country = $country; $this->touch(); return $this; }

    public function getAppType(): string { return $this->appType; }

    /** @return array<string, mixed>|null */
    public function getRawData(): ?array { return $this->rawData; }

    /** @param array<string, mixed>|null $rawData */
    public function setRawData(?array $rawData): self { $this->rawData = $rawData; $this->touch(); return $this; }

    public function getLastLoginAt(): \DateTimeImmutable { return $this->lastLoginAt; }

    public function setLastLoginAt(\DateTimeImmutable $lastLoginAt): self { $this->lastLoginAt = $lastLoginAt; $this->touch(); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

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

    /**
     * @return array<string, int|string|null>
     */
    public function __metadata(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user->getId(),
            'openid' => $this->openid,
            'appType' => $this->appType,
        ];
    }
}
