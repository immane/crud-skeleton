<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseServiceInterface;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;

/** @extends BaseServiceInterface<StoreOrder> */
interface StoreOrderServiceInterface extends BaseServiceInterface
{
    /**
     * Creates or returns the Store projection represented by a Trade order-created event.
     *
     * @param array<string, mixed> $snapshot
     */
    public function createFromTradeOrderSnapshot(Store $store, array $snapshot): StoreOrder;

    public function accept(StoreOrder $storeOrder, ?string $reservationId = null): StoreOrder;

    public function reject(StoreOrder $storeOrder, string $code, string $reason): StoreOrder;

    /** @param array<string, mixed>|null $fulfillmentData */
    public function fulfill(StoreOrder $storeOrder, ?array $fulfillmentData = null): StoreOrder;

    public function verify(StoreOrder $storeOrder, ?string $verifiedBy = null): StoreOrder;
}
