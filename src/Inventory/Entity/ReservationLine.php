<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\ReservationLineRepository;
use App\Inventory\Service\Quantity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationLineRepository::class)]
#[ORM\Table(name: 'inventory_reservation_line')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_reservation_line_material', columns: ['reservation_id', 'material_uuid'])]
class ReservationLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Reservation $reservation = null;

    #[ORM\Column(name: 'material_uuid', type: 'string', length: 36)]
    private string $materialUuid;

    #[ORM\Column(name: 'material_code_snapshot', type: 'string', length: 64)]
    private string $materialCodeSnapshot;

    #[ORM\Column(name: 'unit_snapshot', type: 'string', length: 20)]
    private string $unitSnapshot;

    #[ORM\Column(name: 'requested_quantity', type: 'decimal', precision: 20, scale: 6)]
    private string $requestedQuantity;

    #[ORM\Column(name: 'reserved_quantity', type: 'decimal', precision: 20, scale: 6)]
    private string $reservedQuantity;

    /**
     * @var list<string>
     */
    #[ORM\Column(name: 'source_specification_uuids', type: 'json')]
    private array $sourceSpecificationUuids;

    /**
     * @param list<string> $sourceSpecificationUuids
     */
    public function __construct(Material $material, string $requestedQuantity, array $sourceSpecificationUuids)
    {
        $this->uuid = UUID::v4();
        $this->materialUuid = $material->getUuid();
        $this->materialCodeSnapshot = $material->getCode();
        $this->unitSnapshot = $material->getUnit();
        $this->requestedQuantity = Quantity::normalize($requestedQuantity, true);
        $this->reservedQuantity = $this->requestedQuantity;
        $this->sourceSpecificationUuids = array_values(array_unique($sourceSpecificationUuids));
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }

    public function getMaterialUuid(): string
    {
        return $this->materialUuid;
    }

    public function getReservedQuantity(): string
    {
        return $this->reservedQuantity;
    }

    public function setReservation(Reservation $reservation): void
    {
        $this->reservation = $reservation;
    }
}
