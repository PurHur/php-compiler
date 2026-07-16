<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Built-in GD fonts 1–5 from php-src ext/gd/libgd/gdfont*.c (#6534).
 *
 * Pixel bits are packed MSB-first in ext/gd/fonts/*.bin (php-src 0/1 char arrays).
 */
final class GdFonts
{
    /** @var array<int, array{nchars:int,offset:int,w:int,h:int,data:string}>|null */
    private static ?array $cache = null;

    /**
     * @return array{nchars:int,offset:int,w:int,h:int,data:string}
     */
    public static function get(int $font): array
    {
        if (null === self::$cache) {
            self::$cache = self::loadAll();
        }
        if ($font < 1) {
            $font = 1;
        } elseif ($font > 5) {
            $font = 5;
        }

        return self::$cache[$font];
    }

    /**
     * @return array<int, array{nchars:int,offset:int,w:int,h:int,data:string}>
     */
    private static function loadAll(): array
    {
        $dir = __DIR__.'/fonts';

        return [
            1 => self::load($dir.'/tiny.bin', 256, 0, 5, 8),
            2 => self::load($dir.'/small.bin', 256, 0, 6, 13),
            3 => self::load($dir.'/medium.bin', 256, 0, 7, 13),
            4 => self::load($dir.'/large.bin', 256, 0, 8, 16),
            5 => self::load($dir.'/giant.bin', 256, 0, 9, 15),
        ];
    }

    /**
     * @return array{nchars:int,offset:int,w:int,h:int,data:string}
     */
    private static function load(string $path, int $nchars, int $offset, int $w, int $h): array
    {
        $packed = \file_get_contents($path);
        if (false === $packed) {
            throw new \LogicException('GD font data missing: '.$path);
        }

        return [
            'nchars' => $nchars,
            'offset' => $offset,
            'w' => $w,
            'h' => $h,
            'data' => self::unpackBits($packed, $nchars * $w * $h),
        ];
    }

    private static function unpackBits(string $packed, int $count): string
    {
        $out = '';
        $n = \strlen($packed);
        for ($i = 0; $i < $n && \strlen($out) < $count; ++$i) {
            $byte = \ord($packed[$i]);
            for ($b = 7; $b >= 0 && \strlen($out) < $count; --$b) {
                $out .= (($byte >> $b) & 1) ? "\x01" : "\x00";
            }
        }

        return $out;
    }
}
