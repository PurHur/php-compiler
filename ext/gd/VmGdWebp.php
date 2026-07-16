<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Minimal truecolor WebP (VP8L container) codec for {@see VmGd} (php-src ext/gd/gd.c; #6378).
 *
 * Encodes a VP8L RIFF bitstream whose dimension header matches php-src getimagesize,
 * with a compiler-native PHPC+zlib RGB payload for lossless round-trip without libwebp.
 */
final class VmGdWebp
{
    private const PAYLOAD_MAGIC = 'PHPC';

    /**
     * @param list<int> $pixels flat RGB (0xRRGGBB) scanlines
     */
    public static function encodeRgb(int $width, int $height, array $pixels): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdWebp::encodeRgb() received invalid raster dimensions');
        }

        $rgb = '';
        foreach ($pixels as $color) {
            $color &= 0xFFFFFF;
            $rgb .= \chr(($color >> 16) & 0xFF);
            $rgb .= \chr(($color >> 8) & 0xFF);
            $rgb .= \chr($color & 0xFF);
        }

        // VP8L image-header bitfield: width-1 (14) | height-1 (14) | alpha (1) | version (3).
        $bitsField = (($width - 1) & 0x3FFF)
            | ((($height - 1) & 0x3FFF) << 14);
        $chunkData = "\x2f".pack('V', $bitsField).self::PAYLOAD_MAGIC.gzcompress($rgb, 6);
        $chunkSize = \strlen($chunkData);
        $riffPayload = 'VP8L'.pack('V', $chunkSize).$chunkData;
        if (0 !== ($chunkSize & 1)) {
            $riffPayload .= "\x00";
        }
        $riffSize = 4 + \strlen($riffPayload);

        return 'RIFF'.pack('V', $riffSize).'WEBP'.$riffPayload;
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}|false
     */
    public static function decodeRgb(string $data): array|false
    {
        if (\strlen($data) < 30 || 'RIFF' !== \substr($data, 0, 4) || 'WEBP' !== \substr($data, 8, 4)) {
            return false;
        }
        if ('VP8L' !== \substr($data, 12, 4)) {
            return false;
        }
        $chunkSize = unpack('V', \substr($data, 16, 4))[1] ?? 0;
        if ($chunkSize < 9 || \strlen($data) < 20 + $chunkSize) {
            return false;
        }
        $chunk = \substr($data, 20, $chunkSize);
        if ("\x2f" !== $chunk[0]) {
            return false;
        }
        $bitsField = unpack('V', \substr($chunk, 1, 4))[1] ?? 0;
        $width = ($bitsField & 0x3FFF) + 1;
        $height = (($bitsField >> 14) & 0x3FFF) + 1;
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        if (self::PAYLOAD_MAGIC !== \substr($chunk, 5, 4)) {
            return false;
        }
        $raw = @gzuncompress(\substr($chunk, 9));
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
