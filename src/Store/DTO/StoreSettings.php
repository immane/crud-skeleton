<?php

declare(strict_types=1);

namespace App\Store\DTO;

final readonly class StoreSettings
{
    public function __construct(
        public bool $requireVerification = false,
    ) {
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    public static function from(?array $raw): self
    {
        if ($raw === null) {
            return new self(false);
        }

        $fulfillment = $raw['fulfillment'] ?? null;

        $requireVerification = false;

        if (is_array($fulfillment) && array_key_exists('requireVerification', $fulfillment)) {
            $v = $fulfillment['requireVerification'];
            if (!is_bool($v)) {
                throw new \InvalidArgumentException('settings.fulfillment.requireVerification must be a boolean.');
            }
            $requireVerification = $v;
        }

        if (array_key_exists('fulfillment', $raw) && $raw['fulfillment'] !== null && !is_array($raw['fulfillment'])) {
            throw new \InvalidArgumentException('settings.fulfillment must be an object or null.');
        }

        return new self($requireVerification);
    }

    /** @return array{fulfillment: array{requireVerification: bool}} */
    public function toArray(): array
    {
        return [
            'fulfillment' => ['requireVerification' => $this->requireVerification],
        ];
    }
}
