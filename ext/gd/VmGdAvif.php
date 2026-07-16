<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Minimal AVIF container codec for {@see VmGd} (php-src ext/gd/gd.c; #6378).
 *
 * Builds an ISOBMFF `ftyp`/`meta`/`ispe`/`mdat` skeleton so getimagesize can read
 * dimensions, with a compiler-native PHPC+zlib RGB payload (no libavif required).
 */
final class VmGdAvif
{
    private const PAYLOAD_MAGIC = 'PHPC';

    /**
     * @param list<int> $pixels flat RGB (0xRRGGBB) scanlines
     */
    public static function encodeRgb(int $width, int $height, array $pixels): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdAvif::encodeRgb() received invalid raster dimensions');
        }

        $rgb = '';
        foreach ($pixels as $color) {
            $color &= 0xFFFFFF;
            $rgb .= \chr(($color >> 16) & 0xFF);
            $rgb .= \chr(($color >> 8) & 0xFF);
            $rgb .= \chr($color & 0xFF);
        }
        $payload = self::PAYLOAD_MAGIC.pack('NN', $width, $height).gzcompress($rgb, 6);

        $ftyp = self::box('ftyp', 'avif'.pack('N', 0).'avifmif1');
        $ispe = self::box('ispe', pack('NNN', 0, $width, $height));
        $ipco = self::box('ipco', $ispe);
        $iprp = self::box('iprp', $ipco);
        $meta = self::fullBox('meta', 0, 0, $iprp);
        $mdat = self::box('mdat', $payload);

        return $ftyp.$meta.$mdat;
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}|false
     */
    public static function decodeRgb(string $data): array|false
    {
        $mdat = self::findBoxPayload($data, 'mdat');
        if (false === $mdat || \strlen($mdat) < 12 || self::PAYLOAD_MAGIC !== \substr($mdat, 0, 4)) {
            return false;
        }
        $width = unpack('N', \substr($mdat, 4, 4))[1] ?? 0;
        $height = unpack('N', \substr($mdat, 8, 4))[1] ?? 0;
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $raw = @gzuncompress(\substr($mdat, 12));
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

    /**
     * @return array{0: int, 1: int}|false
     */
    public static function readDimensions(string $data): array|false
    {
        if (\strlen($data) < 12 || 'ftyp' !== \substr($data, 4, 4)) {
            return false;
        }
        if (false === \strpos(\substr($data, 8, 64), 'avif')) {
            return false;
        }
        $ispe = self::findBoxPayload($data, 'ispe');
        if (false !== $ispe && \strlen($ispe) >= 12) {
            $width = unpack('N', \substr($ispe, 4, 4))[1] ?? 0;
            $height = unpack('N', \substr($ispe, 8, 4))[1] ?? 0;
            if ($width > 0 && $height > 0) {
                return [$width, $height];
            }
        }
        $decoded = self::decodeRgb($data);
        if (false === $decoded) {
            return false;
        }

        return [$decoded[0], $decoded[1]];
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + \strlen($payload)).$type.$payload;
    }

    private static function fullBox(string $type, int $version, int $flags, string $payload): string
    {
        $header = \chr($version & 0xFF)
            .\chr(($flags >> 16) & 0xFF)
            .\chr(($flags >> 8) & 0xFF)
            .\chr($flags & 0xFF);

        return self::box($type, $header.$payload);
    }

    private static function findBoxPayload(string $data, string $type): string|false
    {
        $len = \strlen($data);
        $pos = 0;
        while ($pos + 8 <= $len) {
            $size = unpack('N', \substr($data, $pos, 4))[1] ?? 0;
            $boxType = \substr($data, $pos + 4, 4);
            if ($size < 8 || $pos + $size > $len) {
                return false;
            }
            if ($boxType === $type) {
                return \substr($data, $pos + 8, $size - 8);
            }
            if (\in_array($boxType, ['meta', 'iprp', 'ipco'], true)) {
                $offset = 'meta' === $boxType ? 12 : 8;
                if ($size > $offset) {
                    $inner = self::findBoxPayload(\substr($data, $pos + $offset, $size - $offset), $type);
                    if (false !== $inner) {
                        return $inner;
                    }
                }
            }
            $pos += $size;
        }

        return false;
    }
}
