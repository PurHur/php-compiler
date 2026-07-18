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

    /**
     * Decode 8-bit RGB/RGBA PNG (color types 2/6) produced by {@see encodeRgb}/{@see encodeRgba}.
     *
     * @return array{0: int, 1: int, 2: list<int>}|false
     */
    public static function decodeRgb(string $data): array|false
    {
        if (\strlen($data) < 33 || "\x89PNG\r\n\x1a\n" !== \substr($data, 0, 8)) {
            return false;
        }
        $pos = 8;
        $len = \strlen($data);
        $width = 0;
        $height = 0;
        $colorType = -1;
        $idat = '';
        while ($pos + 12 <= $len) {
            $chunkLen = unpack('N', \substr($data, $pos, 4))[1] ?? 0;
            $type = \substr($data, $pos + 4, 4);
            if ($chunkLen < 0 || $pos + 12 + $chunkLen > $len) {
                return false;
            }
            $payload = \substr($data, $pos + 8, $chunkLen);
            if ('IHDR' === $type) {
                if ($chunkLen < 13) {
                    return false;
                }
                $width = unpack('N', \substr($payload, 0, 4))[1] ?? 0;
                $height = unpack('N', \substr($payload, 4, 4))[1] ?? 0;
                $bitDepth = \ord($payload[8]);
                $colorType = \ord($payload[9]);
                if ($width <= 0 || $height <= 0 || 8 !== $bitDepth || !\in_array($colorType, [2, 6], true)) {
                    return false;
                }
            } elseif ('IDAT' === $type) {
                $idat .= $payload;
            } elseif ('IEND' === $type) {
                break;
            }
            $pos += 12 + $chunkLen;
        }
        if ($width <= 0 || $height <= 0 || '' === $idat) {
            return false;
        }
        $raw = @gzinflate($idat);
        if (false === $raw) {
            $raw = @gzuncompress($idat);
        }
        if (false === $raw) {
            return false;
        }
        $bpp = 6 === $colorType ? 4 : 3;
        $rowBytes = 1 + $width * $bpp;
        if (\strlen($raw) !== $rowBytes * $height) {
            return false;
        }
        $pixels = [];
        $prev = str_repeat("\x00", $width * $bpp);
        for ($y = 0; $y < $height; ++$y) {
            $rowOff = $y * $rowBytes;
            $filter = \ord($raw[$rowOff]);
            $scan = \substr($raw, $rowOff + 1, $width * $bpp);
            $recon = self::paethUnfilter($filter, $scan, $prev, $bpp);
            if (false === $recon) {
                return false;
            }
            $prev = $recon;
            for ($x = 0; $x < $width; ++$x) {
                $o = $x * $bpp;
                $r = \ord($recon[$o]);
                $g = \ord($recon[$o + 1]);
                $b = \ord($recon[$o + 2]);
                $pixels[] = ($r << 16) | ($g << 8) | $b;
            }
        }

        return [$width, $height, $pixels];
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

    /**
     * @return string|false
     */
    private static function paethUnfilter(int $filter, string $scan, string $prev, int $bpp)
    {
        $n = \strlen($scan);
        if (0 === $filter) {
            return $scan;
        }
        $out = '';
        for ($i = 0; $i < $n; ++$i) {
            $x = \ord($scan[$i]);
            $a = $i >= $bpp ? \ord($out[$i - $bpp]) : 0;
            $b = \ord($prev[$i]);
            $c = $i >= $bpp ? \ord($prev[$i - $bpp]) : 0;
            $val = match ($filter) {
                1 => ($x + $a) & 0xFF,
                2 => ($x + $b) & 0xFF,
                3 => ($x + intdiv($a + $b, 2)) & 0xFF,
                4 => ($x + self::paethPredictor($a, $b, $c)) & 0xFF,
                default => -1,
            };
            if ($val < 0) {
                return false;
            }
            $out .= \chr($val);
        }

        return $out;
    }

    private static function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }

        return $c;
    }
}
