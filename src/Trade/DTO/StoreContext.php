<?php

declare(strict_types=1);

namespace App\Trade\DTO;

final readonly class StoreContext
{
    public function __construct(
        public string $storeUuid,
        public string $storeCode,
        public string $storeName,
        public string $channel = 'api',
        public string $currency = 'CNY',
        public bool $requireAcceptance = false,
        public bool $requireInventory = false,
        public bool $requireVerification = false,
    ) {
    }

    /** @return array{uuid: string, code: string, name: string, channel: string, currency: string, requireAcceptance: bool, requireInventory: bool, requireVerification: bool} */
    public function toSnapshot(): array
    {
        return [
            'uuid' => $this->storeUuid,
            'code' => $this->storeCode,
            'name' => $this->storeName,
            'channel' => $this->channel,
            'currency' => $this->currency,
            'requireAcceptance' => $this->requireAcceptance,
            'requireInventory' => $this->requireInventory,
            'requireVerification' => $this->requireVerification,
        ];
    }
}
