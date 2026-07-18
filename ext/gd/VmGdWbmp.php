<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * WBMP Type 0 (B/W uncompressed) codec — php-src ext/gd/libgd/wbmp.c + gd_wbmp.c (#20472).
 *
 * Bit convention: 1 = white, 0 = black; MSB-first within each byte; rows padded to octets.
 */
final class VmGdWbmp
{
    public const WHITE = 1;

    public const BLACK = 0;

    /**
     * @param list<int> $pixels flat RGB (0xRRGGBB) scanlines top→bottom
     * @param int       $fg     truecolor/palette pixel value treated as black foreground
     */
    public static function encodeRgb(int $width, int $height, array $pixels, int $fg): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdWbmp::encodeRgb() received invalid raster dimensions');
        }
        $out = "\x00\x00"; // type 0 + fixed header
        $out .= self::putMbi($width);
        $out .= self::putMbi($height);
        for ($y = 0; $y < $height; ++$y) {
            $bitpos = 8;
            $octet = 0;
            $rowBase = $y * $width;
            for ($x = 0; $x < $width; ++$x) {
                --$bitpos;
                // Foreground matches → black (0); else white (1).
                if ($pixels[$rowBase + $x] !== $fg) {
                    $octet |= 1 << $bitpos;
                }
                if (0 === $bitpos) {
                    $out .= \chr($octet);
                    $bitpos = 8;
                    $octet = 0;
                }
            }
            if (8 !== $bitpos) {
                $out .= \chr($octet);
            }
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}|false width, height, RGB pixels
     */
    public static function decodeRgb(string $data): array|false
    {
        $len = \strlen($data);
        if ($len < 4) {
            return false;
        }
        $pos = 0;
        $type = \ord($data[$pos++]);
        if (0 !== $type) {
            return false;
        }
        // skip ExtHeader (continuation bit 0x80)
        do {
            if ($pos >= $len) {
                return false;
            }
            $b = \ord($data[$pos++]);
        } while (0 !== ($b & 0x80));

        $width = self::getMbi($data, $pos);
        if (null === $width || $width <= 0) {
            return false;
        }
        $height = self::getMbi($data, $pos);
        if (null === $height || $height <= 0) {
            return false;
        }
        $pixels = [];
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width;) {
                if ($pos >= $len) {
                    return false;
                }
                $byte = \ord($data[$pos++]);
                for ($pel = 7; $pel >= 0; --$pel) {
                    if ($x++ < $width) {
                        $pixels[] = (0 !== ($byte & (1 << $pel))) ? 0xFFFFFF : 0x000000;
                    }
                }
            }
        }
        if (\count($pixels) !== $width * $height) {
            return false;
        }

        return [$width, $height, $pixels];
    }

    private static function putMbi(int $i): string
    {
        if ($i < 0) {
            throw new \LogicException('VmGdWbmp::putMbi() negative');
        }
        $bytes = [];
        do {
            $bytes[] = $i & 0x7f;
            $i >>= 7;
        } while ($i > 0);
        $bytes = \array_reverse($bytes);
        $n = \count($bytes);
        $out = '';
        for ($k = 0; $k < $n; ++$k) {
            $out .= \chr(($k < $n - 1 ? 0x80 : 0) | $bytes[$k]);
        }

        return $out;
    }

    private static function getMbi(string $data, int &$pos): ?int
    {
        $mbi = 0;
        $len = \strlen($data);
        do {
            if ($pos >= $len) {
                return null;
            }
            $i = \ord($data[$pos++]);
            $mbi = ($mbi << 7) | ($i & 0x7f);
        } while (0 !== ($i & 0x80));

        return $mbi;
    }
}
