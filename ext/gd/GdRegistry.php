<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\VM\ObjectEntry;

/** Maps GdImage object ids to decode state (php-src ext/gd/gd.c; #6215). */
final class GdRegistry
{
    /** @var array<int, GdImageState> */
    private static array $states = [];

    public static function attach(ObjectEntry $image, GdImageState $state): void
    {
        self::$states[$image->id] = $state;
    }

    public static function state(ObjectEntry $image): ?GdImageState
    {
        return self::$states[$image->id] ?? null;
    }

    public static function forget(ObjectEntry $image): void
    {
        unset(self::$states[$image->id]);
    }
}
