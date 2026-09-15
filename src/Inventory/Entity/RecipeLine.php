<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\RecipeLineRepository;
use App\Inventory\Service\Quantity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecipeLineRepository::class)]
#[ORM\Table(name: 'inventory_recipe_line')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_recipe_line_material', columns: ['recipe_id', 'material_id'])]
class RecipeLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: SpecificationRecipe::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SpecificationRecipe $recipe = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Material $material;

    #[ORM\Column(name: 'quantity_per_unit', type: 'decimal', precision: 20, scale: 6)]
    private string $quantityPerUnit;

    #[ORM\Column(type: 'integer')]
    private int $sort;

    public function __construct(Material $material, string $quantityPerUnit, int $sort = 0)
    {
        $this->uuid = UUID::v4();
        $this->material = $material;
        $this->quantityPerUnit = Quantity::normalize($quantityPerUnit, true);
        $this->sort = $sort;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getRecipe(): ?SpecificationRecipe
    {
        return $this->recipe;
    }

    public function getMaterial(): Material
    {
        return $this->material;
    }

    public function getQuantityPerUnit(): string
    {
        return $this->quantityPerUnit;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setRecipe(SpecificationRecipe $recipe): void
    {
        $this->recipe = $recipe;
    }
}
