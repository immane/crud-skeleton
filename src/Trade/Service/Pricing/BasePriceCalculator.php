<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

use App\Trade\Exception\SpecificationNotFoundException;
use App\Trade\Service\Catalog\CatalogResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trade.price_calculator')]
class BasePriceCalculator implements PriceCalculatorInterface
{
    public function __construct(
        private readonly CatalogResolverInterface $catalogResolver,
    ) {
    }

    public static function getPriority(): int
    {
        return -100;
    }

    public function calculate(PriceCalculationContext $context): void
    {
        foreach ($context->inputItems as $inputItem) {
            $specificationId = $inputItem['specificationId'];
            $quantity = $inputItem['quantity'] ?? 1;

            $catalogItem = $this->catalogResolver->resolveForPricing($specificationId, $context->storeCode);

            if ($catalogItem === null) {
                throw new SpecificationNotFoundException(
                    sprintf('Specification #%s not found or not available.', (string) $specificationId)
                );
            }

            $context->items[] = [
                'specificationId' => $catalogItem->id,
                'specificationUuid' => $catalogItem->uuid,
                'specificationName' => $catalogItem->name,
                'quantity' => $quantity,
                'unitPrice' => $catalogItem->price,
                'price' => 0,
                'specSnapshot' => [
                    'id' => $catalogItem->id,
                    'uuid' => $catalogItem->uuid,
                    'name' => $catalogItem->name,
                    'productId' => $catalogItem->productId,
                ],
                'productSnapshot' => [
                    'id' => $catalogItem->productId,
                    'uuid' => $catalogItem->productUuid,
                    'name' => $catalogItem->productName,
                ],
            ];
        }
    }
}
