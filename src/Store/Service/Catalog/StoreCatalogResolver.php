<?php

declare(strict_types=1);

namespace App\Store\Service\Catalog;

use App\Store\Service\SpecificationServiceInterface;
use App\Store\Service\StoreServiceInterface;
use App\Trade\Service\Catalog\CatalogItem;
use App\Trade\Service\Catalog\CatalogResolverInterface;

final readonly class StoreCatalogResolver implements CatalogResolverInterface
{
    public function __construct(
        private SpecificationServiceInterface $specificationService,
        private StoreServiceInterface $storeService,
    ) {
    }

    public function resolveForPricing(int|string $specificationId, ?string $storeCode): ?CatalogItem
    {
        /** @var \App\Store\Entity\Specification|null $specification */
        $specification = $this->specificationService->get($specificationId);
        if ($specification === null || $specification->getIsDeleted() || !$specification->isActive()) {
            return null;
        }

        $product = $specification->getProduct();
        if ($product === null || $product->getIsDeleted() || !$product->isActive()) {
            return null;
        }

        // Store visibility - via BaseService::get() (core pattern, handles uuid/code uniformly)
        $productStore = $product->getStore();
        if ($storeCode !== null && $storeCode !== '') {
            /** @var \App\Store\Entity\Store|null $store */
            $store = $this->storeService->get(['code' => $storeCode]);
            if ($productStore !== null) {
                if ($store === null || $productStore->getId() !== $store->getId()) {
                    return null;
                }
            }
        } elseif ($productStore !== null) {
            return null;
        }

        return new CatalogItem(
            id: $specification->getId() ?? 0,
            uuid: $specification->getUuid(),
            name: $specification->getName(),
            price: $specification->getPrice(),
            status: $specification->getStatus(),
            isDeleted: $specification->getIsDeleted(),
            productId: $product->getId() ?? 0,
            productUuid: $product->getUuid(),
            productName: $product->getName(),
            productIsDeleted: $product->getIsDeleted(),
            productStatus: $product->getStatus(),
            storeUuid: $productStore?->getUuid(),
            storeId: $productStore?->getId(),
        );
    }
}
