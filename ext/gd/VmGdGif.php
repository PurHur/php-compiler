<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Minimal GIF89a container codec for {@see VmGd} (php-src ext/gd/gd.c; #20458).
 *
 * Logical-screen dimensions match php-src getimagesize; RGB payload is
 * compiler-native PHPC+zlib (no libgif required).
 */
final class VmGdGif
{
    private const PAYLOAD_MAGIC = 'PHPC';

    /**
     * @param list<int> $pixels flat RGB (0xRRGGBB) scanlines
     */
    public static function encodeRgb(int $width, int $height, array $pixels): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdGif::encodeRgb() received invalid raster dimensions');
        }
        if ($width > 0xFFFF || $height > 0xFFFF) {
            throw new \LogicException('VmGdGif::encodeRgb() dimensions exceed GIF limits');
        }

        $rgb = '';
        foreach ($pixels as $color) {
            $color &= 0xFFFFFF;
            $rgb .= \chr(($color >> 16) & 0xFF);
            $rgb .= \chr(($color >> 8) & 0xFF);
            $rgb .= \chr($color & 0xFF);
        }
        $payload = self::PAYLOAD_MAGIC.pack('NN', $width, $height).gzcompress($rgb, 6);

        // GIF89a + LSD (no GCT) + Comment Extension carrying PHPC payload + trailer.
        $out = 'GIF89a';
        $out .= pack('v', $width);
        $out .= pack('v', $height);
        $out .= "\x00\x00\x00"; // packed / bg / aspect
        $out .= "\x21\xFE"; // comment extension
        $remaining = $payload;
        while ('' !== $remaining) {
            $chunk = \substr($remaining, 0, 255);
            $remaining = \substr($remaining, 255);
            $out .= \chr(\strlen($chunk)).$chunk;
        }
        $out .= "\x00"; // block terminator
        $out .= "\x3B"; // trailer

        return $out;
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}|false
     */
    public static function decodeRgb(string $data): array|false
    {
        if (\strlen($data) < 14 || ('GIF87a' !== \substr($data, 0, 6) && 'GIF89a' !== \substr($data, 0, 6))) {
            return false;
        }
        $width = unpack('v', \substr($data, 6, 2))[1] ?? 0;
        $height = unpack('v', \substr($data, 8, 2))[1] ?? 0;
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $packed = \ord($data[10]);
        $pos = 13;
        if (0 !== ($packed & 0x80)) {
            $gctSize = 3 * (1 << (($packed & 0x07) + 1));
            if ($pos + $gctSize > \strlen($data)) {
                return false;
            }
            $pos += $gctSize;
        }
        $len = \strlen($data);
        while ($pos < $len) {
            $b = \ord($data[$pos]);
            if (0x3B === $b) {
                break;
            }
            if (0x21 === $b) {
                if ($pos + 2 >= $len) {
                    return false;
                }
                $label = \ord($data[$pos + 1]);
                $pos += 2;
                $block = '';
                while ($pos < $len) {
                    $sz = \ord($data[$pos]);
                    ++$pos;
                    if (0 === $sz) {
                        break;
                    }
                    if ($pos + $sz > $len) {
                        return false;
                    }
                    $block .= \substr($data, $pos, $sz);
                    $pos += $sz;
                }
                if (0xFE === $label
                    && \strlen($block) >= 12
                    && self::PAYLOAD_MAGIC === \substr($block, 0, 4)
                ) {
                    $pw = unpack('N', \substr($block, 4, 4))[1] ?? 0;
                    $ph = unpack('N', \substr($block, 8, 4))[1] ?? 0;
                    if ($pw <= 0 || $ph <= 0) {
                        return false;
                    }
                    $raw = @gzuncompress(\substr($block, 12));
                    if (false === $raw || \strlen($raw) !== $pw * $ph * 3) {
                        return false;
                    }
                    $pixels = [];
                    for ($i = 0, $n = $pw * $ph; $i < $n; ++$i) {
                        $o = $i * 3;
                        $pixels[] = (\ord($raw[$o]) << 16) | (\ord($raw[$o + 1]) << 8) | \ord($raw[$o + 2]);
                    }

                    return [$pw, $ph, $pixels];
                }

                continue;
            }
            if (0x2C === $b) {
                // Skip image descriptor + local color table + LZW sub-blocks (foreign GIFs).
                return false;
            }
            ++$pos;
        }

        return false;
    }
}
