<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * base_convert() / radix parse for compiled JIT/AOT modules (#9584, #26884, php-in-PHP).
 *
 * NestedJIT thin-AOT constraints (#26884):
 * - Do not call {@see VmMath} — NestedJIT stubs unresolved cross-class statics to null.
 * - Avoid sprintf / ctype_* / unpack.
 * - Use substr() for portable one-byte chars (Zend offset ≠ NestedJIT int byte).
 * - Prefer recursion over while/for accumulation — NestedJIT loop induction failed to
 *   advance past the first digit (hexdec("ff") → 15) while unrolled/recursive paths are green.
 * - Bridge must pass `__string__*`.
 *
 * SSOT algorithm mirrors VmMath::baseToZval / baseConvert.
 * php-src: ext/standard/math.c
 */
final class MathBaseConvertJitHelper
{
    private static int $lastLong = 0;

    private static float $lastDouble = 0.0;

    private static bool $lastInvalidChars = false;

    public static function baseConvert(string $number, int $fromBase, int $toBase): string
    {
        if ($fromBase < 2 || $fromBase > 36) {
            throw new \ValueError('base_convert(): Argument #2 ($from_base) must be between 2 and 36 (inclusive)');
        }
        if ($toBase < 2 || $toBase > 36) {
            throw new \ValueError('base_convert(): Argument #3 ($to_base) must be between 2 and 36 (inclusive)');
        }

        $tag = self::parseBaseToZval($number, $fromBase);
        if (1 === $tag) {
            return self::doubleToBase(self::$lastDouble, $toBase);
        }

        return self::longToBase(self::$lastLong, $toBase);
    }

    /** @return int 0=long result, 1=double result (LLVM i32 ABI) */
    public static function parseBaseToZval(string $str, int $base): int
    {
        self::$lastInvalidChars = false;
        self::$lastLong = 0;
        self::$lastDouble = 0.0;

        $len = \strlen($str);
        $start = self::skipLeadingSpaces($str, 0, $len);
        $end = self::skipTrailingSpaces($str, $start, $len);
        $start = self::skipRadixPrefix($str, $start, $end, $base);

        $parsed = self::parseRec($str, $start, $end, $base, 0);
        if ($parsed < 0) {
            self::$lastDouble = self::floatRec($str, $start, $end, $base, 0.0);

            return 1;
        }
        self::$lastLong = $parsed;

        return 0;
    }

    public static function lastLong(): int
    {
        return self::$lastLong;
    }

    public static function lastDouble(): float
    {
        return self::$lastDouble;
    }

    /** @return int LLVM i32 ABI — 1 when last parse skipped invalid digits (#24950). */
    public static function lastInvalidChars(): int
    {
        return self::$lastInvalidChars ? 1 : 0;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastLong = 0;
        self::$lastDouble = 0.0;
        self::$lastInvalidChars = false;
    }

    private static function skipLeadingSpaces(string $str, int $start, int $len): int
    {
        if ($start >= $len) {
            return $start;
        }
        if (!self::isSpaceChar(\substr($str, $start, 1))) {
            return $start;
        }

        return self::skipLeadingSpaces($str, $start + 1, $len);
    }

    private static function skipTrailingSpaces(string $str, int $start, int $end): int
    {
        if ($end <= $start) {
            return $end;
        }
        if (!self::isSpaceChar(\substr($str, $end - 1, 1))) {
            return $end;
        }

        return self::skipTrailingSpaces($str, $start, $end - 1);
    }

    private static function skipRadixPrefix(string $str, int $start, int $end, int $base): int
    {
        if ($end - $start < 2) {
            return $start;
        }
        $c0 = \substr($str, $start, 1);
        $c1 = \substr($str, $start + 1, 1);
        if (16 === $base && '0' === $c0 && ('x' === $c1 || 'X' === $c1)) {
            return $start + 2;
        }
        if (8 === $base && '0' === $c0 && ('o' === $c1 || 'O' === $c1)) {
            return $start + 2;
        }
        if (2 === $base && '0' === $c0 && ('b' === $c1 || 'B' === $c1)) {
            return $start + 2;
        }

        return $start;
    }

    /**
     * Recursive digit walk — NestedJIT-safe (#26884).
     *
     * @return int accumulated long, or -1 when integer range exhausted (use floatRec)
     */
    private static function parseRec(string $str, int $pos, int $end, int $base, int $num): int
    {
        if ($pos >= $end) {
            return $num;
        }
        // NestedJIT may drop the radix to 0 on recursive calls; sdiv 0 is SIGFPE (#31966).
        if ($base < 2 || $base > 36) {
            return $num;
        }
        $digit = self::radixDigitChar(\substr($str, $pos, 1), $base);
        if ($digit < 0) {
            self::$lastInvalidChars = true;

            return self::parseRec($str, $pos + 1, $end, $base, $num);
        }

        $cutoffDiv = (int) (9223372036854775807 / $base);
        $cutlim = 9223372036854775807 - $cutoffDiv * $base;
        if ($num > $cutoffDiv || ($num === $cutoffDiv && $digit > $cutlim)) {
            return -1;
        }

        return self::parseRec($str, $pos + 1, $end, $base, $num * $base + $digit);
    }

    private static function floatRec(string $str, int $pos, int $end, int $base, float $fnum): float
    {
        if ($pos >= $end) {
            return $fnum;
        }
        $digit = self::radixDigitChar(\substr($str, $pos, 1), $base);
        if ($digit < 0) {
            self::$lastInvalidChars = true;

            return self::floatRec($str, $pos + 1, $end, $base, $fnum);
        }

        return self::floatRec($str, $pos + 1, $end, $base, $fnum * (float) $base + (float) $digit);
    }

    private static function isSpaceChar(string $c): bool
    {
        return ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c || "\v" === $c || "\f" === $c;
    }

    /** @return int digit or -1 when invalid for $base */
    private static function radixDigitChar(string $c, int $base): int
    {
        if ('0' === $c) {
            $digit = 0;
        } elseif ('1' === $c) {
            $digit = 1;
        } elseif ('2' === $c) {
            $digit = 2;
        } elseif ('3' === $c) {
            $digit = 3;
        } elseif ('4' === $c) {
            $digit = 4;
        } elseif ('5' === $c) {
            $digit = 5;
        } elseif ('6' === $c) {
            $digit = 6;
        } elseif ('7' === $c) {
            $digit = 7;
        } elseif ('8' === $c) {
            $digit = 8;
        } elseif ('9' === $c) {
            $digit = 9;
        } elseif ('A' === $c || 'a' === $c) {
            $digit = 10;
        } elseif ('B' === $c || 'b' === $c) {
            $digit = 11;
        } elseif ('C' === $c || 'c' === $c) {
            $digit = 12;
        } elseif ('D' === $c || 'd' === $c) {
            $digit = 13;
        } elseif ('E' === $c || 'e' === $c) {
            $digit = 14;
        } elseif ('F' === $c || 'f' === $c) {
            $digit = 15;
        } elseif ('G' === $c || 'g' === $c) {
            $digit = 16;
        } elseif ('H' === $c || 'h' === $c) {
            $digit = 17;
        } elseif ('I' === $c || 'i' === $c) {
            $digit = 18;
        } elseif ('J' === $c || 'j' === $c) {
            $digit = 19;
        } elseif ('K' === $c || 'k' === $c) {
            $digit = 20;
        } elseif ('L' === $c || 'l' === $c) {
            $digit = 21;
        } elseif ('M' === $c || 'm' === $c) {
            $digit = 22;
        } elseif ('N' === $c || 'n' === $c) {
            $digit = 23;
        } elseif ('O' === $c || 'o' === $c) {
            $digit = 24;
        } elseif ('P' === $c || 'p' === $c) {
            $digit = 25;
        } elseif ('Q' === $c || 'q' === $c) {
            $digit = 26;
        } elseif ('R' === $c || 'r' === $c) {
            $digit = 27;
        } elseif ('S' === $c || 's' === $c) {
            $digit = 28;
        } elseif ('T' === $c || 't' === $c) {
            $digit = 29;
        } elseif ('U' === $c || 'u' === $c) {
            $digit = 30;
        } elseif ('V' === $c || 'v' === $c) {
            $digit = 31;
        } elseif ('W' === $c || 'w' === $c) {
            $digit = 32;
        } elseif ('X' === $c || 'x' === $c) {
            $digit = 33;
        } elseif ('Y' === $c || 'y' === $c) {
            $digit = 34;
        } elseif ('Z' === $c || 'z' === $c) {
            $digit = 35;
        } else {
            return -1;
        }

        return $digit < $base ? $digit : -1;
    }

    private static function longToBase(int $arg, int $base): string
    {
        if ($base < 2 || $base > 36) {
            return '';
        }
        if (0 === $arg) {
            return '0';
        }

        $negative = $arg < 0;
        $n = $negative ? -$arg : $arg;
        if ($negative && $arg === -9223372036854775807 - 1) {
            return self::doubleToBase((float) $arg, $base);
        }
        $digits = '0123456789abcdefghijklmnopqrstuvwxyz';
        $out = '';
        while ($n > 0) {
            $out = $digits[$n % $base].$out;
            $n = (int) ($n / $base);
        }

        return $negative ? '-'.$out : $out;
    }

    private static function doubleToBase(float $fvalue, int $base): string
    {
        if ($base < 2 || $base > 36) {
            return '';
        }
        if ($fvalue === \INF || $fvalue === -\INF) {
            throw new \ValueError('An infinite value cannot be converted to base '.(string) $base);
        }

        if ($fvalue >= 0.0) {
            $fvalue = (float) ((int) $fvalue);
        } else {
            $trunc = (float) ((int) $fvalue);
            $fvalue = $trunc === $fvalue ? $trunc : $trunc - 1.0;
        }
        if (0.0 === $fvalue) {
            return '0';
        }

        $negative = $fvalue < 0.0;
        if ($negative) {
            $fvalue = -$fvalue;
        }

        $digits = '0123456789abcdefghijklmnopqrstuvwxyz';
        $buf = '';
        while ($fvalue >= 1.0) {
            $whole = (int) ($fvalue / (float) $base);
            $digit = (int) ($fvalue - ((float) $whole) * (float) $base);
            if ($digit < 0) {
                $digit = 0;
            }
            if ($digit >= $base) {
                $digit = $base - 1;
            }
            $buf = $digits[$digit].$buf;
            $fvalue = $fvalue / (float) $base;
        }

        return $negative ? '-'.$buf : $buf;
    }
}
