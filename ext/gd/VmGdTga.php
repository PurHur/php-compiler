<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * TGA decode — php-src ext/gd/libgd/gd_tga.c (#20503).
 *
 * Supports uncompressed RGB (type 2) and RLE RGB (type 10) at 24 bpp (no alpha)
 * and 32 bpp (8 alpha bits). Output is truecolor ARGB (libgd alpha 0 opaque..127).
 */
final class VmGdTga
{
    public const TYPE_RGB = 2;

    public const TYPE_RGB_RLE = 10;

    public const BPP_24 = 24;

    public const BPP_32 = 32;

    public const RLE_FLAG = 0x80;

    public const ALPHA_MAX = 127;

    /**
     * @return array{0: int, 1: int, 2: list<int>, 3: bool}|false width, height, ARGB pixels, hasAlpha
     */
    public static function decodeRgb(string $data): array|false
    {
        $len = \strlen($data);
        if ($len < 18) {
            return false;
        }
        $identsize = \ord($data[0]);
        $imagetype = \ord($data[2]);
        $width = \ord($data[12]) | (\ord($data[13]) << 8);
        $height = \ord($data[14]) | (\ord($data[15]) << 8);
        $bits = \ord($data[16]);
        $desc = \ord($data[17]);
        $alphabits = $desc & 0x0f;
        $fliph = (0 !== ($desc & 0x10)) ? 1 : 0;
        $flipv = (0 !== ($desc & 0x20)) ? 0 : 1;

        if ($width <= 0 || $height <= 0) {
            return false;
        }
        if (!((self::BPP_24 === $bits && 0 === $alphabits)
            || (self::BPP_32 === $bits && 8 === $alphabits))) {
            return false;
        }
        if (self::TYPE_RGB !== $imagetype && self::TYPE_RGB_RLE !== $imagetype) {
            return false;
        }

        $pos = 18;
        if ($identsize > 0) {
            if ($pos + $identsize > $len) {
                return false;
            }
            $pos += $identsize;
        }

        $bpp = (int) ($bits / 8);
        $imageBytes = $width * $height * $bpp;
        $raw = self::TYPE_RGB === $imagetype
            ? self::readUncompressed($data, $pos, $len, $imageBytes)
            : self::readRle($data, $pos, $len, $imageBytes, $bpp);
        if (false === $raw) {
            return false;
        }

        $pixels = [];
        $caret = 0;
        $hasAlpha = self::BPP_32 === $bits && $alphabits > 0;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $b = \ord($raw[$caret]);
                $g = \ord($raw[$caret + 1]);
                $r = \ord($raw[$caret + 2]);
                if ($hasAlpha) {
                    $a = \ord($raw[$caret + 3]);
                    // gdTrueColorAlpha(..., gdAlphaMax - (a >> 1))
                    $ga = self::ALPHA_MAX - ($a >> 1);
                    $pixels[] = ($ga << 24) | ($r << 16) | ($g << 8) | $b;
                    $caret += 4;
                } else {
                    $pixels[] = ($r << 16) | ($g << 8) | $b;
                    $caret += 3;
                }
            }
        }

        if (1 === $flipv && 1 === $fliph) {
            $pixels = self::flipBoth($pixels, $width, $height);
        } elseif (1 === $flipv) {
            $pixels = self::flipVertical($pixels, $width, $height);
        } elseif (1 === $fliph) {
            $pixels = self::flipHorizontal($pixels, $width, $height);
        }

        return [$width, $height, $pixels, $hasAlpha];
    }

    /**
     * Build a minimal uncompressed 24-bpp top-left-origin TGA (for tests).
     *
     * @param list<int> $rgbPixels 0xRRGGBB scanlines top→bottom
     */
    public static function encodeUncompressed24(int $width, int $height, array $rgbPixels): string
    {
        if (\count($rgbPixels) !== $width * $height) {
            throw new \LogicException('VmGdTga::encodeUncompressed24() size mismatch');
        }
        // descriptor 0x20 → top-left origin (flipv=0)
        $out = \chr(0).\chr(0).\chr(self::TYPE_RGB);
        $out .= \pack('v', 0).\pack('v', 0).\chr(0); // colormap
        $out .= \pack('v', 0).\pack('v', 0); // origin
        $out .= \pack('v', $width).\pack('v', $height);
        $out .= \chr(24).\chr(0x20); // 24bpp, top-left
        for ($i = 0; $i < $width * $height; ++$i) {
            $c = $rgbPixels[$i] & 0xFFFFFF;
            $out .= \chr($c & 0xFF).\chr(($c >> 8) & 0xFF).\chr(($c >> 16) & 0xFF);
        }

        return $out;
    }

    private static function readUncompressed(string $data, int $pos, int $len, int $need): string|false
    {
        if ($pos + $need > $len) {
            return false;
        }

        return \substr($data, $pos, $need);
    }

    private static function readRle(string $data, int $pos, int $len, int $imageBytes, int $bpp): string|false
    {
        $out = '';
        $bitmapCaret = 0;
        while ($bitmapCaret < $imageBytes) {
            if ($pos >= $len) {
                return false;
            }
            $packet = \ord($data[$pos++]);
            if (0 !== ($packet & self::RLE_FLAG)) {
                $count = ($packet & ~self::RLE_FLAG) + 1;
                if ($pos + $bpp > $len) {
                    return false;
                }
                $pix = \substr($data, $pos, $bpp);
                $pos += $bpp;
                if ($bitmapCaret + $count * $bpp > $imageBytes) {
                    return false;
                }
                for ($i = 0; $i < $count; ++$i) {
                    $out .= $pix;
                    $bitmapCaret += $bpp;
                }
            } else {
                $count = $packet + 1;
                $need = $count * $bpp;
                if ($pos + $need > $len || $bitmapCaret + $need > $imageBytes) {
                    return false;
                }
                $out .= \substr($data, $pos, $need);
                $pos += $need;
                $bitmapCaret += $need;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $pixels
     * @return list<int>
     */
    private static function flipVertical(array $pixels, int $width, int $height): array
    {
        $out = [];
        for ($y = $height - 1; $y >= 0; --$y) {
            $base = $y * $width;
            for ($x = 0; $x < $width; ++$x) {
                $out[] = $pixels[$base + $x];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $pixels
     * @return list<int>
     */
    private static function flipHorizontal(array $pixels, int $width, int $height): array
    {
        $out = [];
        for ($y = 0; $y < $height; ++$y) {
            $base = $y * $width;
            for ($x = $width - 1; $x >= 0; --$x) {
                $out[] = $pixels[$base + $x];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $pixels
     * @return list<int>
     */
    private static function flipBoth(array $pixels, int $width, int $height): array
    {
        return self::flipHorizontal(self::flipVertical($pixels, $width, $height), $width, $height);
    }
}
