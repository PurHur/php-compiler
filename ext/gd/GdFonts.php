<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Built-in GD fonts 1–5 from php-src ext/gd/libgd/gdfont*.c (#6534).
 *
 * Pixel bits are packed MSB-first in ext/gd/fonts/*.bin (php-src 0/1 char arrays).
 * Loaded `.gdf` dumps (imageloadfont) use the same payload shape (#20486).
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
     * Parse architecture-dependent gdFont dump (php-src PHP_FUNCTION(imageloadfont)).
     *
     * Header: 4× int32 (nchars, offset, w, h); body: nchars×w×h bytes (one per pixel).
     *
     * @return array{nchars:int,offset:int,w:int,h:int,data:string}|null
     */
    public static function parseGdf(string $bytes): ?array
    {
        $hdrSize = 16;
        $len = \strlen($bytes);
        if ($len < $hdrSize) {
            return null;
        }
        $nchars = self::readI32($bytes, 0);
        $offset = self::readI32($bytes, 4);
        $w = self::readI32($bytes, 8);
        $h = self::readI32($bytes, 12);
        $bodyCheck = $len - $hdrSize;
        if (!self::bodySizeOk($nchars, $w, $h, $bodyCheck)) {
            $nchars = self::flipI32($nchars);
            $offset = self::flipI32($offset);
            $w = self::flipI32($w);
            $h = self::flipI32($h);
            if (!self::bodySizeOk($nchars, $w, $h, $bodyCheck)) {
                return null;
            }
        }
        $bodySize = $nchars * $w * $h;
        $raw = \substr($bytes, $hdrSize, $bodySize);
        if (\strlen($raw) !== $bodySize) {
            return null;
        }

        return [
            'nchars' => $nchars,
            'offset' => $offset,
            'w' => $w,
            'h' => $h,
            'data' => self::normalizePixelBytes($raw),
        ];
    }

    private static function bodySizeOk(int $nchars, int $w, int $h, int $bodyCheck): bool
    {
        if ($nchars <= 0 || $w <= 0 || $h <= 0) {
            return false;
        }
        if ($nchars > 0x7FFFFFFF / $h) {
            return false;
        }
        $nh = $nchars * $h;
        if ($nh > 0x7FFFFFFF / $w) {
            return false;
        }

        return ($nh * $w) === $bodyCheck;
    }

    private static function readI32(string $bytes, int $off): int
    {
        $u = \unpack('V', \substr($bytes, $off, 4));

        return (int) $u[1];
    }

    private static function flipI32(int $v): int
    {
        return (($v & 0xFF) << 24)
            | (($v & 0xFF00) << 8)
            | (($v >> 8) & 0xFF00)
            | (($v >> 24) & 0xFF);
    }

    private static function normalizePixelBytes(string $raw): string
    {
        $out = '';
        $n = \strlen($raw);
        for ($i = 0; $i < $n; ++$i) {
            $out .= ("\x00" === $raw[$i]) ? "\x00" : "\x01";
        }

        return $out;
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
