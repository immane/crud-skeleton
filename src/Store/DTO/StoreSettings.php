<?php

declare(strict_types=1);

namespace App\Store\DTO;

final readonly class StoreSettings
{
    public function __construct(
        public bool $requireAcceptance = false,
        public bool $requireInventory = false,
        public bool $requireVerification = false,
    ) {
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    public static function from(?array $raw): self
    {
        if ($raw === null) {
            return new self(false, false, false);
        }

        $order = $raw['order'] ?? null;
        $fulfillment = $raw['fulfillment'] ?? null;

        $requireAcceptance = false;
        $requireInventory = false;
        $requireVerification = false;

        if (is_array($order) && array_key_exists('requireAcceptance', $order)) {
            $v = $order['requireAcceptance'];
            if (!is_bool($v)) {
                throw new \InvalidArgumentException('settings.order.requireAcceptance must be a boolean.');
            }
            $requireAcceptance = $v;
        }

        if (is_array($order) && array_key_exists('requireInventory', $order)) {
            $v = $order['requireInventory'];
            if (!is_bool($v)) {
                throw new \InvalidArgumentException('settings.order.requireInventory must be a boolean.');
            }
            $requireInventory = $v;
        }

        if (is_array($fulfillment) && array_key_exists('requireVerification', $fulfillment)) {
            $v = $fulfillment['requireVerification'];
            if (!is_bool($v)) {
                throw new \InvalidArgumentException('settings.fulfillment.requireVerification must be a boolean.');
            }
            $requireVerification = $v;
        }

        if (array_key_exists('order', $raw) && $raw['order'] !== null && !is_array($raw['order'])) {
            throw new \InvalidArgumentException('settings.order must be an object or null.');
        }
        if (array_key_exists('fulfillment', $raw) && $raw['fulfillment'] !== null && !is_array($raw['fulfillment'])) {
            throw new \InvalidArgumentException('settings.fulfillment must be an object or null.');
        }

        return new self($requireAcceptance, $requireInventory, $requireVerification);
    }

    /** @return array{order: array{requireAcceptance: bool, requireInventory: bool}, fulfillment: array{requireVerification: bool}} */
    public function toArray(): array
    {
        return [
            'order' => ['requireAcceptance' => $this->requireAcceptance, 'requireInventory' => $this->requireInventory],
            'fulfillment' => ['requireVerification' => $this->requireVerification],
        ];
    }
}
