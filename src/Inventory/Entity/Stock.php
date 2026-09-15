<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\StockRepository;
use App\Inventory\Service\Quantity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockRepository::class)]
#[ORM\Table(name: 'inventory_stock')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_stock_store_material', columns: ['store_uuid', 'material_id'])]
#[ORM\Index(name: 'idx_inventory_stock_store_updated', columns: ['store_uuid', 'updated_at'])]
class Stock
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Material $material;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid;

    #[ORM\Column(name: 'on_hand_quantity', type: 'decimal', precision: 20, scale: 6)]
    private string $onHandQuantity = Quantity::ZERO;

    #[ORM\Column(name: 'reserved_quantity', type: 'decimal', precision: 20, scale: 6)]
    private string $reservedQuantity = Quantity::ZERO;

    #[ORM\Column(name: 'allow_negative_stock', type: 'boolean')]
    private bool $allowNegativeStock = false;

    #[ORM\Version, ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $storeUuid, Material $material, bool $allowNegativeStock = false)
    {
        $this->uuid = UUID::v4();
        $this->storeUuid = $storeUuid;
        $this->material = $material;
        $this->allowNegativeStock = $allowNegativeStock;
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

    public function getMaterial(): Material
    {
        return $this->material;
    }

    public function getStoreUuid(): string
    {
        return $this->storeUuid;
    }

    public function getOnHandQuantity(): string
    {
        return $this->onHandQuantity;
    }

    public function getReservedQuantity(): string
    {
        return $this->reservedQuantity;
    }

    public function getAvailableQuantity(): string
    {
        return Quantity::subtract($this->onHandQuantity, $this->reservedQuantity);
    }

    public function allowsNegativeStock(): bool
    {
        return $this->allowNegativeStock;
    }

    public function setAllowNegativeStock(bool $allow): self
    {
        if (!$allow && Quantity::compare($this->getAvailableQuantity(), Quantity::ZERO) < 0) {
            throw new \LogicException('Negative stock cannot be disabled while available quantity is negative.');
        }

        $this->allowNegativeStock = $allow;

        return $this->touch();
    }

    public function adjustOnHand(string $delta): void
    {
        $this->onHandQuantity = Quantity::add($this->onHandQuantity, $delta);
        $this->touch();
    }

    public function reserve(string $quantity): void
    {
        $quantity = Quantity::normalize($quantity, true);
        $this->reservedQuantity = Quantity::add($this->reservedQuantity, $quantity);
        $this->touch();
    }

    public function release(string $quantity): void
    {
        $quantity = Quantity::normalize($quantity, true);

        if (Quantity::compare($this->reservedQuantity, $quantity) < 0) {
            throw new \LogicException('Reserved quantity cannot become negative.');
        }

        $this->reservedQuantity = Quantity::subtract($this->reservedQuantity, $quantity);
        $this->touch();
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
