<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * pack() for compiled JIT/AOT modules via PackEngine PHP (#9133, php-in-PHP).
 *
 * NestedJIT constraints (#22990 / #22981):
 * - No `$a[] = …` array growth (appends dropped under NestedJIT).
 * - No by-ref int/string params (mis-store / segfault under NestedJIT).
 * - No bool params / bool locals driving ternaries (endian always takes false branch).
 * - Stream-decode the argv blob; append encode chunks to `$out`.
 *
 * php-src: ext/standard/pack.c — php_pack()
 */
final class PackJitHelper
{
    private const TAG_NULL = 0;

    private const TAG_LONG = 1;

    private const TAG_DOUBLE = 2;

    private const TAG_BOOL = 3;

    private const TAG_STRING = 4;

    private const TAG_ARRAY = 5;

    public static function packedArrayMarker(): PackedArgvArrayMarker
    {
        return new PackedArgvArrayMarker();
    }

    /**
     * @param string $packedArgs length-prefixed argv blob from LLVM bridge
     */
    public static function packArgv(string $format, string $packedArgs): string
    {
        return self::packFromBlob($format, $packedArgs);
    }

    /** NestedJIT-safe pack (#22990): prefer simple format fast-paths NestedJIT can execute. */
    private static function packFromBlob(string $format, string $blob): string
    {
        $blen = \strlen($blob);

        // Exact-format fast paths — NestedJIT handles these without format scanners (#22990).
        // Use putLongLe/Be (no bool) — NestedJIT bool args always take the false ternary arm.
        // Accept TAG_LONG and TAG_BOOL for integer formats (bool packs as 0/1).
        if ('N' === $format) {
            if ($blen >= 9) {
                $tag = \ord($blob[0]);
                if (1 === $tag || 3 === $tag) {
                    return PackEngineEncode::putLongBe(self::readInt64Le($blob, 1), 4);
                }
            }
        } elseif ('V' === $format) {
            if ($blen >= 9) {
                $tag = \ord($blob[0]);
                if (1 === $tag || 3 === $tag) {
                    return PackEngineEncode::putLongLe(self::readInt64Le($blob, 1), 4);
                }
            }
        } elseif ('n' === $format) {
            if ($blen >= 9) {
                $tag = \ord($blob[0]);
                if (1 === $tag || 3 === $tag) {
                    return PackEngineEncode::putLongBe(self::readInt64Le($blob, 1), 2);
                }
            }
        } elseif ('v' === $format) {
            if ($blen >= 9) {
                $tag = \ord($blob[0]);
                if (1 === $tag || 3 === $tag) {
                    return PackEngineEncode::putLongLe(self::readInt64Le($blob, 1), 2);
                }
            }
        } elseif ('l' === $format || 'L' === $format) {
            if ($blen >= 9) {
                $tag = \ord($blob[0]);
                if (1 === $tag || 3 === $tag) {
                    return PackEngineEncode::putLongLe(self::readInt64Le($blob, 1), 4);
                }
            }
        } elseif ('c' === $format || 'C' === $format) {
            if ($blen >= 9) {
                $tag = \ord($blob[0]);
                if (1 === $tag || 3 === $tag) {
                    return PackEngineEncode::putLongLe(self::readInt64Le($blob, 1), 1);
                }
            }
        } elseif ('f' === $format && $blen >= 9 && 2 === \ord($blob[0])) {
            return self::float64LeBlobToFloat32Le(\substr($blob, 1, 8));
        } elseif ('d' === $format && $blen >= 9 && 2 === \ord($blob[0])) {
            return \substr($blob, 1, 8);
        } elseif (\strlen($format) >= 1) {
            $code0 = $format[0];
            if ('a' === $code0 || 'A' === $code0 || 'Z' === $code0) {
                return self::packStringFormat($format, $blob, $blen);
            }
        }

        // n* / v* / N* / … — separate loops (no bool $le under NestedJIT).
        if ('n*' === $format) {
            return self::packLongStarBe($blob, $blen, 2);
        }
        if ('N*' === $format) {
            return self::packLongStarBe($blob, $blen, 4);
        }
        if ('v*' === $format) {
            return self::packLongStarLe($blob, $blen, 2);
        }
        if ('V*' === $format || 'l*' === $format || 'L*' === $format) {
            return self::packLongStarLe($blob, $blen, 4);
        }
        if ('c*' === $format || 'C*' === $format) {
            return self::packLongStarLe($blob, $blen, 1);
        }

        // No PackJitEngine fallback — NestedJIT of that unit segfaults / empties (#22990).
        return '';
    }

    /**
     * NestedJIT-safe a/A/Z (#22990). Caller guarantees format starts with a/A/Z.
     */
    private static function packStringFormat(string $format, string $blob, int $blen): string
    {
        $code = $format[0];
        if ($blen < 9 || 4 !== \ord($blob[0])) {
            return '';
        }
        $slen = self::readInt64Le($blob, 1);
        if ($slen < 0 || 9 + $slen > $blen) {
            return '';
        }
        $str = \substr($blob, 9, $slen);
        $rest = \substr($format, 1);
        if ('*' === $rest) {
            $arg = $slen;
        } elseif ('' === $rest) {
            $arg = 1;
        } else {
            $arg = self::parseDecimal($rest);
            if ($arg < 0) {
                return '';
            }
        }
        if ('a' === $code) {
            return PackEngineEncode::padRight($str, $arg, "\0");
        }
        if ('A' === $code) {
            return PackEngineEncode::padRight($str, $arg, ' ');
        }
        // Z — NUL-terminated; last byte is NUL when arg > 0 (php-src pack.c).
        if ($arg <= 0) {
            return '';
        }
        if (1 === $arg) {
            return "\0";
        }
        $body = PackEngineEncode::padRight($str, $arg - 1, "\0");

        return $body."\0";
    }

    /** @return int negative on non-decimal */
    private static function parseDecimal(string $digits): int
    {
        $n = 0;
        $len = \strlen($digits);
        $i = 0;
        while ($i < $len) {
            $o = \ord($digits[$i]);
            if ($o < 48 || $o > 57) {
                return -1;
            }
            $n = $n * 10 + ($o - 48);
            ++$i;
        }

        return $n;
    }

    private static function packLongStarBe(string $blob, int $blen, int $size): string
    {
        $out = '';
        $bpos = 0;
        while ($bpos + 9 <= $blen && 1 === \ord($blob[$bpos])) {
            $out .= PackEngineEncode::putLongBe(self::readInt64Le($blob, $bpos + 1), $size);
            $bpos = $bpos + 9;
        }

        return $out;
    }

    private static function packLongStarLe(string $blob, int $blen, int $size): string
    {
        $out = '';
        $bpos = 0;
        while ($bpos + 9 <= $blen && 1 === \ord($blob[$bpos])) {
            $out .= PackEngineEncode::putLongLe(self::readInt64Le($blob, $bpos + 1), $size);
            $bpos = $bpos + 9;
        }

        return $out;
    }

    /**
     * Truncate IEEE754 float64 LE bytes to float32 LE without NestedJIT float ops (#22990).
     * php-src: ext/standard/pack.c float encode path.
     */
    private static function float64LeBlobToFloat32Le(string $bytes): string
    {
        $lo = (\ord($bytes[0])
            | (\ord($bytes[1]) << 8)
            | (\ord($bytes[2]) << 16)
            | (\ord($bytes[3]) << 24)) & 0xFFFFFFFF;
        $hi = (\ord($bytes[4])
            | (\ord($bytes[5]) << 8)
            | (\ord($bytes[6]) << 16)
            | (\ord($bytes[7]) << 24)) & 0xFFFFFFFF;
        $sign = ($hi >> 31) & 1;
        $exp = ($hi >> 20) & 0x7FF;
        $mantHi = $hi & 0xFFFFF;
        // 52-bit mantissa as high 20 + low 32.
        if (0x7FF === $exp) {
            // Inf / NaN — preserve quiet NaN bit when mantissa nonzero.
            $f32 = ($sign << 31) | 0x7F800000;
            if (0 !== $mantHi || 0 !== $lo) {
                $f32 |= 0x400000;
            }

            return PackEngineEncode::putLongLe($f32, 4);
        }
        if (0 === $exp && 0 === $mantHi && 0 === $lo) {
            return PackEngineEncode::putLongLe($sign << 31, 4);
        }
        // Rebias; take top 23 bits of the 52-bit fraction (bit 51..29) with round-to-nearest.
        $newExp = $exp - 1023 + 127;
        if (0 === $exp) {
            // Subnormals → signed zero for NestedJIT simplicity (rare for pack argv).
            return PackEngineEncode::putLongLe($sign << 31, 4);
        }
        if ($newExp >= 0xFF) {
            return PackEngineEncode::putLongLe(($sign << 31) | 0x7F800000, 4);
        }
        if ($newExp <= 0) {
            return PackEngineEncode::putLongLe($sign << 31, 4);
        }
        // Fraction bits: mantHi[19:0] | lo[31:0] — shift so bit 51 lands at bit 22 of f32.
        // Top 23 of 52 = (mant << 3) high part from mantHi and lo.
        $frac = (($mantHi << 3) | (($lo >> 29) & 7)) & 0x7FFFFF;
        $roundBit = ($lo >> 28) & 1;
        $sticky = 0;
        if (($lo & 0x0FFFFFFF) !== 0) {
            $sticky = 1;
        }
        if (1 === $roundBit) {
            if (1 === $sticky || 0 !== ($frac & 1)) {
                ++$frac;
                if ($frac >= 0x800000) {
                    $frac = 0;
                    ++$newExp;
                    if ($newExp >= 0xFF) {
                        return PackEngineEncode::putLongLe(($sign << 31) | 0x7F800000, 4);
                    }
                }
            }
        }
        $f32 = ($sign << 31) | (($newExp & 0xFF) << 23) | ($frac & 0x7FFFFF);

        return PackEngineEncode::putLongLe($f32, 4);
    }

    /**
     * Decode argv blob for other JIT helpers (#9131) — host/Zend path.
     * NestedJIT pack must not rely on this (array append broken — #22990).
     *
     * @return list<int|float|bool|string|null>
     */
    public static function decodePackedArgv(string $packed): array
    {
        return self::unpackArgv($packed);
    }

    /**
     * @return list<int|float|bool|string|null>
     */
    private static function unpackArgv(string $packed): array
    {
        $args = [];
        $len = \strlen($packed);
        $pos = 0;
        while ($pos < $len) {
            $tag = \ord($packed[$pos++]);
            switch ($tag) {
                case self::TAG_NULL:
                    $args[] = null;
                    break;
                case self::TAG_LONG:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $args[] = self::readInt64Le($packed, $pos);
                    $pos += 8;
                    break;
                case self::TAG_DOUBLE:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $args[] = Ieee754::decodeFloat64Le(\substr($packed, $pos, 8));
                    $pos += 8;
                    break;
                case self::TAG_BOOL:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $args[] = 0 !== self::readInt64Le($packed, $pos);
                    $pos += 8;
                    break;
                case self::TAG_STRING:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $sl = self::readInt64Le($packed, $pos);
                    $pos += 8;
                    if ($sl < 0 || $pos + $sl > $len) {
                        break 2;
                    }
                    $args[] = \substr($packed, $pos, $sl);
                    $pos += $sl;
                    break;
                case self::TAG_ARRAY:
                    $args[] = self::packedArrayMarker();
                    break;
                default:
                    break 2;
            }
        }

        return $args;
    }

    private static function readInt64Le(string $bytes, int $pos): int
    {
        $lo = (\ord($bytes[$pos])
            | (\ord($bytes[$pos + 1]) << 8)
            | (\ord($bytes[$pos + 2]) << 16)
            | (\ord($bytes[$pos + 3]) << 24)) & 0xFFFFFFFF;
        $hi = (\ord($bytes[$pos + 4])
            | (\ord($bytes[$pos + 5]) << 8)
            | (\ord($bytes[$pos + 6]) << 16)
            | (\ord($bytes[$pos + 7]) << 24)) & 0xFFFFFFFF;

        return ($hi << 32) | $lo;
    }
}

/** @internal argv blob marker for array operands in sprintf JIT bridge (#13598). */
final class PackedArgvArrayMarker
{
}
