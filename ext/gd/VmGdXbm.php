<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * X11 XBM codec — php-src ext/gd/libgd/gd_xbm.c (#20472).
 *
 * Bits are LSB-first within each byte; rows padded to octet width.
 * A set bit means the foreground (black) pixel.
 */
final class VmGdXbm
{
    /**
     * @param list<int> $pixels flat RGB (0xRRGGBB) scanlines top→bottom
     */
    public static function encodeRgb(int $width, int $height, array $pixels, int $fg, string $name = 'image'): string
    {
        if ($width <= 0 || $height <= 0 || \count($pixels) !== $width * $height) {
            throw new \LogicException('VmGdXbm::encodeRgb() received invalid raster dimensions');
        }
        $ident = self::sanitizeIdent($name);
        $out = '#define '.$ident."_width {$width}\n";
        $out .= '#define '.$ident."_height {$height}\n";
        $out .= 'static unsigned char '.$ident."_bits[] = {\n  ";

        $b = 1;
        $p = 0;
        $c = 0;
        for ($y = 0; $y < $height; ++$y) {
            $rowBase = $y * $width;
            for ($x = 0; $x < $width; ++$x) {
                if ($pixels[$rowBase + $x] === $fg) {
                    $c |= $b;
                }
                if (128 === $b || $x === $width - 1) {
                    $b = 1;
                    if ($p > 0) {
                        $out .= ', ';
                        if (0 === ($p % 12)) {
                            $out .= "\n  ";
                        }
                    }
                    $out .= \sprintf('0x%02x', $c);
                    ++$p;
                    $c = 0;
                } else {
                    $b <<= 1;
                }
            }
        }
        $out .= "\n};\n";

        return $out;
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}|false
     */
    public static function decodeRgb(string $data): array|false
    {
        $width = 0;
        $height = 0;
        $maxBit = 0;
        $lines = \preg_split("/\r\n|\n|\r/", $data) ?: [];
        $bitLine = null;
        foreach ($lines as $idx => $fline) {
            if (\strlen($fline) >= 254) {
                return false;
            }
            if (1 === \preg_match('/^#define\s+(\S+)\s+(\d+)\s*$/', $fline, $m)) {
                $iname = $m[1];
                $value = (int) $m[2];
                $type = self::trailingIdent($iname);
                if ('width' === $type) {
                    $width = $value;
                } elseif ('height' === $type) {
                    $height = $value;
                }
                continue;
            }
            if (1 === \preg_match('/static\s+unsigned\s+char\s+(\S+)\s*=\s*\{/', $fline, $m)
                || 1 === \preg_match('/static\s+char\s+(\S+)\s*=\s*\{/', $fline, $m)) {
                $maxBit = 128;
            } elseif (1 === \preg_match('/static\s+unsigned\s+short\s+(\S+)\s*=\s*\{/', $fline, $m)
                || 1 === \preg_match('/static\s+short\s+(\S+)\s*=\s*\{/', $fline, $m)) {
                $maxBit = 32768;
            } else {
                continue;
            }
            $type = self::trailingIdent($m[1]);
            if ('bits[]' === $type || 'bits' === $type) {
                $bitLine = $idx;
                break;
            }
            $maxBit = 0;
        }
        if ($width <= 0 || $height <= 0 || 0 === $maxBit || null === $bitLine) {
            return false;
        }
        $bytesNeeded = (int) (($width + 7) / 8) * $height;
        if ($bytesNeeded <= 0) {
            return false;
        }
        // Collect hex tokens after the bits[] opener.
        $rest = \implode("\n", \array_slice($lines, $bitLine));
        if (!\preg_match_all('/0[xX]([0-9a-fA-F]+)/', $rest, $hexMatches)) {
            return false;
        }
        $values = $hexMatches[1];
        if (\count($values) < $bytesNeeded && 128 === $maxBit) {
            // tolerate missing trailing padding tokens by zero-fill below
        }
        $pixels = \array_fill(0, $width * $height, 0xFFFFFF);
        $x = 0;
        $y = 0;
        $vi = 0;
        for ($i = 0; $i < $bytesNeeded; ++$i) {
            $raw = $values[$vi] ?? '0';
            ++$vi;
            if (32768 === $maxBit) {
                // short values consume one hex token of up to 4 digits
                $b = \hexdec($raw);
            } else {
                $b = \hexdec(\substr($raw, 0, 2));
            }
            for ($bit = 1; $bit <= $maxBit; $bit <<= 1) {
                $pixels[$y * $width + $x] = (0 !== ($b & $bit)) ? 0x000000 : 0xFFFFFF;
                ++$x;
                if ($x === $width) {
                    $x = 0;
                    ++$y;
                    if ($y === $height) {
                        return [$width, $height, $pixels];
                    }
                    break;
                }
            }
        }

        return [$width, $height, $pixels];
    }

    private static function trailingIdent(string $iname): string
    {
        $pos = \strrpos($iname, '_');
        if (false === $pos) {
            return $iname;
        }

        return \substr($iname, $pos + 1);
    }

    private static function sanitizeIdent(string $name): string
    {
        $base = $name;
        $slash = \strrpos($base, '/');
        if (false !== $slash) {
            $base = \substr($base, $slash + 1);
        }
        $bslash = \strrpos($base, '\\');
        if (false !== $bslash) {
            $base = \substr($base, $bslash + 1);
        }
        if (1 === \preg_match('/\.xbm$/i', $base)) {
            $base = \substr($base, 0, -4);
        }
        if ('' === $base) {
            return 'image';
        }
        $out = '';
        $len = \strlen($base);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $base[$i];
            $ord = \ord($ch);
            $alnum = ($ord >= 48 && $ord <= 57)
                || ($ord >= 65 && $ord <= 90)
                || ($ord >= 97 && $ord <= 122);
            $out .= $alnum ? $ch : '_';
        }

        return '' !== $out ? $out : 'image';
    }
}
