<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Store\DTO\StoreSettings;
use App\Store\Entity\Store;

final readonly class StoreSettingsResolver
{
    public function resolve(Store $store): StoreSettings
    {
        return StoreSettings::from($store->getSettings());
    }

    /**
     * @param array<string, mixed>|null $settings
     */
    public function fromArray(?array $settings): StoreSettings
    {
        return StoreSettings::from($settings);
    }
}
