<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * pack() binary encode helpers — separate compile unit for nested JIT (#13062).
 *
 * php-src: ext/standard/pack.c
 *
 * Keep this unit free of Frame/ErrorReporter/PackEngine so PackJitHelper nested
 * AOT emit stays lean (#22831).
 *
 * Do **not** call host `\pack()` / `\unpack()` here: NestedJIT of this unit is on
 * the pack() helper path, and `\pack` → StringPack → PackEngineEncode → `\pack`
 * is the #22981 / #22843 non-termination cycle. Byte encode with chr/shifts
 * (peer {@see Ieee754::u32ToBytes}).
 */
final class PackEngineEncode
{
    public static function machineLe(): bool
    {
        // NestedJIT of nullable static ?bool props mis-stores __value__* (#22990).
        // Committed helper-runtime arches (x86_64/aarch64 *-linux) are little-endian
        // (#22981); no runtime probe.
        return true;
    }

    public static function putLong(int $value, int $size, bool $littleEndian): string
    {
        // Host/Zend path. NestedJIT mishandles bool args (#22990) — AOT uses putLongLe/Be.
        return $littleEndian ? self::putLongLe($value, $size) : self::putLongBe($value, $size);
    }

    /** NestedJIT-safe LE encode — no bool param (#22990). */
    public static function putLongLe(int $value, int $size): string
    {
        switch ($size) {
            case 1:
                return \chr($value & 0xFF);
            case 2:
                return self::u16ToBytesLe($value & 0xFFFF);
            case 8:
                return self::u32ToBytesLe($value & 0xFFFFFFFF)
                    .self::u32ToBytesLe(($value >> 32) & 0xFFFFFFFF);
            case 4:
            default:
                return self::u32ToBytesLe($value & 0xFFFFFFFF);
        }
    }

    /** NestedJIT-safe BE encode — no bool param (#22990). */
    public static function putLongBe(int $value, int $size): string
    {
        switch ($size) {
            case 1:
                return \chr($value & 0xFF);
            case 2:
                return self::u16ToBytesBe($value & 0xFFFF);
            case 8:
                return self::u32ToBytesBe(($value >> 32) & 0xFFFFFFFF)
                    .self::u32ToBytesBe($value & 0xFFFFFFFF);
            case 4:
            default:
                return self::u32ToBytesBe($value & 0xFFFFFFFF);
        }
    }

    private static function u16ToBytesLe(int $bits): string
    {
        return \chr($bits & 0xFF).\chr(($bits >> 8) & 0xFF);
    }

    private static function u16ToBytesBe(int $bits): string
    {
        return \chr(($bits >> 8) & 0xFF).\chr($bits & 0xFF);
    }

    private static function u32ToBytesLe(int $bits): string
    {
        return \chr($bits & 0xFF)
            .\chr(($bits >> 8) & 0xFF)
            .\chr(($bits >> 16) & 0xFF)
            .\chr(($bits >> 24) & 0xFF);
    }

    private static function u32ToBytesBe(int $bits): string
    {
        return \chr(($bits >> 24) & 0xFF)
            .\chr(($bits >> 16) & 0xFF)
            .\chr(($bits >> 8) & 0xFF)
            .\chr($bits & 0xFF);
    }

    public static function putFloat(float $value, bool $littleEndian): string
    {
        return $littleEndian ? self::putFloatLe($value) : Ieee754::encodeFloat32($value, false);
    }

    public static function putDouble(float $value, bool $littleEndian): string
    {
        return $littleEndian ? self::putDoubleLe($value) : Ieee754::encodeFloat64($value, false);
    }

    /** NestedJIT-safe machine-endian float (#22990). */
    public static function putFloatLe(float $value): string
    {
        return Ieee754::encodeFloat32Le($value);
    }

    /** NestedJIT-safe machine-endian double (#22990). */
    public static function putDoubleLe(float $value): string
    {
        return Ieee754::encodeFloat64Le($value);
    }

    public static function writeAt(string $output, int $pos, string $chunk): string
    {
        $need = $pos + \strlen($chunk);
        // Avoid \str_repeat — NestedJIT of this unit may lack __compiler_str_repeat (#22981).
        while (\strlen($output) < $need) {
            $output .= "\0";
        }

        return \substr($output, 0, $pos).$chunk.\substr($output, $pos + \strlen($chunk));
    }

    /** Null-byte run without \str_repeat (#22981 NestedJIT). */
    public static function zeros(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $out = '';
        while ($n-- > 0) {
            $out .= "\0";
        }

        return $out;
    }

    /** Right-pad without \str_pad (#22981 NestedJIT). */
    public static function padRight(string $str, int $len, string $pad): string
    {
        $cur = \strlen($str);
        if ($cur >= $len) {
            return \substr($str, 0, $len);
        }
        $padChar = '' === $pad ? "\0" : $pad[0];
        while ($cur < $len) {
            $str .= $padChar;
            ++$cur;
        }

        return $str;
    }

    /**
     * Hex nibble pack for format `H`/`h` (php-src pack.c).
     *
     * Illegal hex digits emit E_WARNING then coerce to 0 (#22831).
     * Nested JIT/AOT uses host trigger_error (no Frame); VM path is {@see PackEngine}.
     *
     * @param bool $highNibbleFirst true for `H`, false for `h`
     */
    public static function packHex(string $str, int $arg, bool $highNibbleFirst): string
    {
        $out = '';
        $remain = $arg;
        $pos = 0;
        $slen = \strlen($str);
        $nibbleShift = $highNibbleFirst ? 4 : 0;
        $type = $highNibbleFirst ? 'H' : 'h';
        $first = true;
        $byte = 0;

        if ($remain > $slen) {
            $remain = $slen;
        }

        while ($remain-- > 0) {
            $digit = $str[$pos++];
            $n = self::hexNibble($digit);
            if ($n < 0) {
                // php-src: php_error_docref(NULL, E_WARNING, "Type %c: illegal hex digit %c", code, n);
                @\trigger_error(
                    'pack(): Type '.$type.': illegal hex digit '.$digit,
                    \E_USER_WARNING
                );
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

        // php-src ext/standard/pack.c: odd nibble count emits high/low nibble as one byte (#12217).
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
