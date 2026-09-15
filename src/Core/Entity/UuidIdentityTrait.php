<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

trait UuidIdentityTrait
{
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    private function initializeUuid(): void
    {
        $this->uuid = UUID::v4();
    }

    #[ORM\PrePersist]
    public function ensureUuid(): void
    {
        if (!isset($this->uuid)) {
            $this->initializeUuid();
        }
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }
}
