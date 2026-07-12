<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * ext/gd surface advertisement — php-src ext/gd/gd.c (#11675, #6215).
 *
 * Decode builtins ({@see imagecreatefromstring}) register when this returns true.
 * Canvas drawing ({@see imagecreate}) remains stubbed until libgd parity (#3496).
 */
final class GdExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function advertisesDecodeFromString(): bool
    {
        return self::advertisesExtension();
    }
}
