<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\VM\ObjectEntry;

/**
 * Maps GdFont object ids to loaded bitmap font payloads (php-src ext/gd/gd.c; #20486).
 */
final class GdFontRegistry
{
    /** @var array<int, array{nchars:int,offset:int,w:int,h:int,data:string}> */
    private static array $fonts = [];

    /**
     * @param array{nchars:int,offset:int,w:int,h:int,data:string} $font
     */
    public static function attach(ObjectEntry $font, array $fontData): void
    {
        self::$fonts[$font->id] = $fontData;
    }

    /**
     * @return array{nchars:int,offset:int,w:int,h:int,data:string}|null
     */
    public static function font(ObjectEntry $font): ?array
    {
        return self::$fonts[$font->id] ?? null;
    }

    public static function forget(ObjectEntry $font): void
    {
        unset(self::$fonts[$font->id]);
    }
}
