<?php

namespace App\Core\Serializer;

final class ExpansionMetadata
{
    /** @var \WeakMap<object, true>|null */
    private static ?\WeakMap $expanded = null;

    public static function mark(object $object): void
    {
        self::expanded()[$object] = true;
    }

    public static function isMarked(object $object): bool
    {
        return isset(self::expanded()[$object]);
    }

    public static function clear(): void
    {
        self::$expanded = null;
    }

    /** @return \WeakMap<object, true> */
    private static function expanded(): \WeakMap
    {
        return self::$expanded ??= new \WeakMap();
    }
}
