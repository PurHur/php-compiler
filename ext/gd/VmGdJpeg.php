<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Minimal JPEG container codec for {@see VmGd} (php-src ext/gd/gd.c; #20458).
 *
 * Emits a JFIF bitstream whose SOF0 dimensions match php-src getimagesize,
 * with a compiler-native PHPC+zlib RGB payload (no libjpeg required).
 */
final class VmGdJpeg
{
    private const PAYLOAD_MAGIC = 'PHPC';

    /**
     * @param list<int> $pixels flat RGB (0xRRGGBB) scanlines
     */
    public static function encodeRgb(int $width, int $height, array $pixels, int $quality = 75): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdJpeg::encodeRgb() received invalid raster dimensions');
        }
        // Quality is accepted for signature parity with php-src imagejpeg(); payload stays lossless.
        if ($quality < 0) {
            $quality = 0;
        } elseif ($quality > 100) {
            $quality = 100;
        }
        unset($quality);

        $rgb = '';
        foreach ($pixels as $color) {
            $color &= 0xFFFFFF;
            $rgb .= \chr(($color >> 16) & 0xFF);
            $rgb .= \chr(($color >> 8) & 0xFF);
            $rgb .= \chr($color & 0xFF);
        }
        $payload = self::PAYLOAD_MAGIC.pack('NN', $width, $height).gzcompress($rgb, 6);

        $soi = "\xFF\xD8";
        $app0 = self::segment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00");
        $com = self::segment(0xFE, $payload);
        // SOF0: precision 8, height, width, 3 components (YCbCr ids) — enough for getimagesize.
        $sof = self::segment(
            0xC0,
            "\x08".pack('n', $height).pack('n', $width)."\x03"
            ."\x01\x11\x00\x02\x11\x01\x03\x11\x01"
        );
        $eoi = "\xFF\xD9";

        return $soi.$app0.$com.$sof.$eoi;
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}|false
     */
    public static function decodeRgb(string $data): array|false
    {
        if (\strlen($data) < 4 || "\xFF\xD8" !== \substr($data, 0, 2)) {
            return false;
        }
        $len = \strlen($data);
        $pos = 2;
        while ($pos + 3 < $len) {
            if (0xFF !== \ord($data[$pos])) {
                ++$pos;

                continue;
            }
            $marker = \ord($data[$pos + 1]);
            if (0xD9 === $marker) {
                break;
            }
            if ($marker >= 0xD0 && $marker <= 0xD7) {
                $pos += 2;

                continue;
            }
            $segmentLen = unpack('n', \substr($data, $pos + 2, 2))[1] ?? 0;
            if ($segmentLen < 2 || $pos + 2 + $segmentLen > $len) {
                return false;
            }
            if (0xFE === $marker) {
                $payload = \substr($data, $pos + 4, $segmentLen - 2);
                if (\strlen($payload) >= 12 && self::PAYLOAD_MAGIC === \substr($payload, 0, 4)) {
                    $width = unpack('N', \substr($payload, 4, 4))[1] ?? 0;
                    $height = unpack('N', \substr($payload, 8, 4))[1] ?? 0;
                    if ($width <= 0 || $height <= 0) {
                        return false;
                    }
                    $raw = @gzuncompress(\substr($payload, 12));
                    if (false === $raw || \strlen($raw) !== $width * $height * 3) {
                        return false;
                    }
                    $pixels = [];
                    for ($i = 0, $n = $width * $height; $i < $n; ++$i) {
                        $o = $i * 3;
                        $pixels[] = (\ord($raw[$o]) << 16) | (\ord($raw[$o + 1]) << 8) | \ord($raw[$o + 2]);
                    }

                    return [$width, $height, $pixels];
                }
            }
            $pos += 2 + $segmentLen;
        }

        return false;
    }

    private static function segment(int $marker, string $payload): string
    {
        return "\xFF".\chr($marker & 0xFF).pack('n', 2 + \strlen($payload)).$payload;
    }
}
