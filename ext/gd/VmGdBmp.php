<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Minimal truecolor BMP (BI_RGB 24-bit) codec for {@see VmGd} (php-src ext/gd; #20417).
 *
 * Encodes/decodes Windows BMP without libgd — bottom-up BGR rows padded to 4 bytes.
 */
final class VmGdBmp
{
    /**
     * @param list<int> $pixels flat RGB (0xRRGGBB) scanlines top→bottom
     */
    public static function encodeRgb(int $width, int $height, array $pixels, bool $compressed = true): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdBmp::encodeRgb() received invalid raster dimensions');
        }
        // Truecolor BMP ignores RLE ($compressed); always BI_RGB 24-bit like common php-src path.
        unset($compressed);

        $rowStride = ($width * 3 + 3) & ~3;
        $pixelSize = $rowStride * $height;
        $offset = 14 + 40;
        $fileSize = $offset + $pixelSize;

        $out = 'BM';
        $out .= pack('V', $fileSize);
        $out .= pack('v', 0);
        $out .= pack('v', 0);
        $out .= pack('V', $offset);
        // BITMAPINFOHEADER
        $out .= pack('V', 40);
        $out .= pack('V', $width);
        $out .= pack('V', $height); // positive = bottom-up
        $out .= pack('v', 1); // planes
        $out .= pack('v', 24); // bit count
        $out .= pack('V', 0); // BI_RGB
        $out .= pack('V', $pixelSize);
        $out .= pack('V', 0); // x ppm
        $out .= pack('V', 0); // y ppm
        $out .= pack('V', 0); // colors used
        $out .= pack('V', 0); // important colors

        $pad = $rowStride - $width * 3;
        $padBytes = $pad > 0 ? str_repeat("\x00", $pad) : '';
        for ($y = $height - 1; $y >= 0; --$y) {
            $rowBase = $y * $width;
            for ($x = 0; $x < $width; ++$x) {
                $c = $pixels[$rowBase + $x] & 0xFFFFFF;
                $out .= \chr($c & 0xFF); // B
                $out .= \chr(($c >> 8) & 0xFF); // G
                $out .= \chr(($c >> 16) & 0xFF); // R
            }
            $out .= $padBytes;
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}|false
     */
    public static function decodeRgb(string $data): array|false
    {
        if (\strlen($data) < 54 || 'BM' !== \substr($data, 0, 2)) {
            return false;
        }
        $fileSize = unpack('V', \substr($data, 2, 4))[1] ?? 0;
        $offset = unpack('V', \substr($data, 10, 4))[1] ?? 0;
        $headerSize = unpack('V', \substr($data, 14, 4))[1] ?? 0;
        if ($headerSize < 40 || $offset < 14 + $headerSize || $offset > \strlen($data)) {
            return false;
        }
        $width = unpack('l', \substr($data, 18, 4))[1] ?? 0;
        $heightRaw = unpack('l', \substr($data, 22, 4))[1] ?? 0;
        $planes = unpack('v', \substr($data, 26, 2))[1] ?? 0;
        $bitCount = unpack('v', \substr($data, 28, 2))[1] ?? 0;
        $compression = unpack('V', \substr($data, 30, 4))[1] ?? 0;
        if (1 !== $planes || $width <= 0 || 0 === $heightRaw) {
            return false;
        }
        $bottomUp = $heightRaw > 0;
        $height = abs($heightRaw);
        // v1: uncompressed 24-bit BI_RGB only
        if (24 !== $bitCount || 0 !== $compression) {
            return false;
        }
        $rowStride = ($width * 3 + 3) & ~3;
        $need = $offset + $rowStride * $height;
        if (\strlen($data) < $need) {
            return false;
        }
        unset($fileSize);

        $pixels = array_fill(0, $width * $height, 0);
        for ($row = 0; $row < $height; ++$row) {
            $srcRow = $bottomUp ? ($height - 1 - $row) : $row;
            $base = $offset + $srcRow * $rowStride;
            $dstBase = $row * $width;
            for ($x = 0; $x < $width; ++$x) {
                $i = $base + $x * 3;
                $b = \ord($data[$i]);
                $g = \ord($data[$i + 1]);
                $r = \ord($data[$i + 2]);
                $pixels[$dstBase + $x] = ($r << 16) | ($g << 8) | $b;
            }
        }

        return [$width, $height, $pixels];
    }
}
