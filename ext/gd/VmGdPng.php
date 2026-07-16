<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Minimal truecolor PNG encoder for {@see VmGd} raster images (php-src ext/gd/gd.c; #3496, #6535).
 */
final class VmGdPng
{
    /**
     * @param list<int> $pixels flat RGB (0xRRGGBB) scanlines — alpha discarded
     */
    public static function encodeRgb(int $width, int $height, array $pixels): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdPng::encodeRgb() received invalid raster dimensions');
        }

        $raw = '';
        for ($y = 0; $y < $height; ++$y) {
            $raw .= "\x00";
            $row = $y * $width;
            for ($x = 0; $x < $width; ++$x) {
                $color = $pixels[$row + $x] & 0xFFFFFF;
                $raw .= \chr(($color >> 16) & 0xFF);
                $raw .= \chr(($color >> 8) & 0xFF);
                $raw .= \chr($color & 0xFF);
            }
        }

        return self::wrapPng($width, $height, 2, $raw);
    }

    /**
     * RGBA PNG when imagesavealpha(true) — GD alpha 0 opaque .. 127 transparent (#6535).
     *
     * @param list<int> $pixels flat ARGB scanlines
     */
    public static function encodeRgba(int $width, int $height, array $pixels): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdPng::encodeRgba() received invalid raster dimensions');
        }

        $raw = '';
        for ($y = 0; $y < $height; ++$y) {
            $raw .= "\x00";
            $row = $y * $width;
            for ($x = 0; $x < $width; ++$x) {
                $color = $pixels[$row + $x];
                $gdAlpha = ($color >> 24) & 0x7F;
                // libgd gd_png.c: map GD 0..127 → PNG 255..0
                $pngAlpha = 255 - (($gdAlpha << 1) + ($gdAlpha >> 6));
                $raw .= \chr(($color >> 16) & 0xFF);
                $raw .= \chr(($color >> 8) & 0xFF);
                $raw .= \chr($color & 0xFF);
                $raw .= \chr($pngAlpha & 0xFF);
            }
        }

        return self::wrapPng($width, $height, 6, $raw);
    }

    private static function wrapPng(int $width, int $height, int $colorType, string $raw): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        $ihdr = self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, $colorType, 0, 0, 0));
        $idat = self::chunk('IDAT', gzdeflate($raw, 6));
        $iend = self::chunk('IEND', '');

        return $signature.$ihdr.$idat.$iend;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)).$type.$data.pack('N', self::crc($type.$data));
    }

    private static function crc(string $data): int
    {
        return crc32($data) & 0xFFFFFFFF;
    }
}
