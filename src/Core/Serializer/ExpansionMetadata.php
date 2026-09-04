<?php

declare(strict_types=1);

namespace App\Core\Serializer;

/**
 * Holds request-scoped expansion targets without mutating Doctrine entities.
 */
final class ExpansionMetadata
{
    /** @var \WeakMap<object, object> */
    private \WeakMap $expanded;

    public function __construct()
    {
        $this->expanded = new \WeakMap();
    }

    public function mark(object $entity): void
    {
        $this->expanded[$entity] = $entity;
    }

    public function get(object $entity): ?object
    {
        return $this->expanded[$entity] ?? null;
    }
}
