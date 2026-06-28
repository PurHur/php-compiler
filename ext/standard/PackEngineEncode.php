<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * pack() binary encode helpers — separate compile unit for nested JIT (#13062).
 *
 * php-src: ext/standard/pack.c
 */
final class PackEngineEncode
{
    private static ?bool $machineLe = null;

    public static function machineLe(): bool
    {
        if (null === self::$machineLe) {
            self::$machineLe = 0 !== \unpack('S', "\x00\x01")[1];
        }

        return self::$machineLe;
    }

    public static function putLong(int $value, int $size, bool $littleEndian): string
    {
        switch ($size) {
            case 1:
                $fmt = 'c';
                break;
            case 2:
                $fmt = 's';
                break;
            case 8:
                $fmt = 'q';
                break;
            case 4:
            default:
                $fmt = 'l';
                break;
        }
        $bytes = \pack($fmt, $value);

        if (\strlen($bytes) > $size) {
            $bytes = \substr($bytes, 0, $size);
        } elseif (\strlen($bytes) < $size) {
            $bytes = \str_pad($bytes, $size, "\0");
        }

        $needSwap = ($littleEndian !== self::machineLe());
        if (!$needSwap) {
            return $bytes;
        }

        return \strrev($bytes);
    }

    public static function putFloat(float $value, bool $littleEndian): string
    {
        return Ieee754::encodeFloat32($value, $littleEndian);
    }

    public static function putDouble(float $value, bool $littleEndian): string
    {
        return Ieee754::encodeFloat64($value, $littleEndian);
    }

    public static function writeAt(string $output, int $pos, string $chunk): string
    {
        $need = $pos + \strlen($chunk);
        if (\strlen($output) < $need) {
            $output .= \str_repeat("\0", $need - \strlen($output));
        }

        return \substr($output, 0, $pos).$chunk.\substr($output, $pos + \strlen($chunk));
    }

    public static function packHex(string $str, int $arg, bool $highNibbleFirst): string
    {
        $out = '';
        $remain = $arg;
        $pos = 0;
        $slen = \strlen($str);
        $nibbleShift = $highNibbleFirst ? 4 : 0;
        $first = true;
        $byte = 0;

        if ($remain > $slen) {
            $remain = $slen;
        }

        while ($remain-- > 0) {
            $n = self::hexNibble($str[$pos++]);
            if ($n < 0) {
                $n = 0;
            }
            if ($first) {
                $byte = 0;
                $first = false;
            } else {
                $first = true;
            }
            $byte |= $n << $nibbleShift;
            $nibbleShift = ($nibbleShift + 4) & 7;
            if ($first) {
                $out .= \chr($byte);
            }
        }

        if (!$first) {
            $out .= \chr($byte);
        }

        return $out;
    }

    private static function hexNibble(string $c): int
    {
        $o = \ord($c);
        if ($o >= 48 && $o <= 57) {
            return $o - 48;
        }
        if ($o >= 65 && $o <= 70) {
            return $o - 65 + 10;
        }
        if ($o >= 97 && $o <= 102) {
            return $o - 97 + 10;
        }

        return -1;
    }
}
