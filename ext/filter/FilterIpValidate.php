<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * FILTER_VALIDATE_IP — NestedJIT/AOT-safe unit (#27207, peer EMAIL #27068 / URL #27206).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_ip
 *
 * Used for compile-time fold SSOT parity checks and the NestedJIT fallback when
 * FILTER_FLAG_NO_* / GLOBAL_RANGE are set. Dynamic zero-flag AOT uses
 * {@see \PHPCompiler\JIT\Builtin\StringFilterIp} + {@see __compiler_inet_pton} (#32571).
 *
 * Keep free of VmFilter / VmInetPure / `\preg_match` / by-ref / `"\0"` / bool NestedJIT returns,
 * explode/implode, str_starts_with/str_contains, mutable static spill, and array returns.
 * Host SSOT for compile-time fold: {@see VmFilter::isValidIpAddress()}.
 */
final class FilterIpValidate
{
    private const FLAG_IPV4 = 0x00100000;

    private const FLAG_IPV6 = 0x00200000;

    private const FLAG_NO_RES_RANGE = 0x00400000;

    private const FLAG_NO_PRIV_RANGE = 0x00800000;

    private const FLAG_GLOBAL_RANGE = 0x10000000;

    public static function isValid(string $s, int $flags = 0): bool
    {
        return 1 === self::isValidInt($s, $flags);
    }

    public static function isValidInt(string $s, int $flags = 0): int
    {
        if ('' === $s) {
            return 0;
        }
        $isV4 = self::isIpv4Text($s);
        $isV6 = 0;
        if (0 === $isV4) {
            $isV6 = self::isIpv6Text($s);
            if (0 === $isV6) {
                return 0;
            }
        }

        $ipv4Only = 0 !== ($flags & self::FLAG_IPV4);
        $ipv6Only = 0 !== ($flags & self::FLAG_IPV6);
        if ($ipv4Only && !$ipv6Only && 0 === $isV4) {
            return 0;
        }
        if ($ipv6Only && !$ipv4Only && 0 === $isV6) {
            return 0;
        }

        $noPriv = 0 !== ($flags & self::FLAG_NO_PRIV_RANGE);
        $noRes = 0 !== ($flags & self::FLAG_NO_RES_RANGE);
        $globalOnly = 0 !== ($flags & self::FLAG_GLOBAL_RANGE);
        if (($noPriv || $noRes || $globalOnly) && 1 === $isV4) {
            if (1 === self::ipv4RejectedByFlags($s, $noPriv, $noRes, $globalOnly)) {
                return 0;
            }
        }
        if (($noPriv || $noRes || $globalOnly) && 1 === $isV6) {
            if ($noRes && 1 === self::ipv6IsReservedNoRes($s)) {
                return 0;
            }
        }

        return 1;
    }

    /**
     * php-src ≤8.2 FILTER_FLAG_NO_RES_RANGE IPv6 set (logical_filters.c) (#29009).
     *
     * @return int 1 when address must be rejected under NO_RES_RANGE
     */
    private static function ipv6IsReservedNoRes(string $addr): int
    {
        if ('::1' === $addr || '::' === $addr) {
            return 1;
        }
        $h0 = self::ipv6LeadingHextet0($addr);
        if ($h0 < 0) {
            return 0;
        }
        $h1 = self::ipv6LeadingHextet1($addr);
        if ($h1 < 0) {
            return 0;
        }
        if ($h0 >= 0xfe80 && $h0 <= 0xfebf) {
            return 1;
        }
        if (0x2001 === $h0 && (0x0db8 === $h1 || ($h1 >= 0x0010 && $h1 <= 0x001f))) {
            return 1;
        }

        return 0;
    }

    /** @return int first hextet, or -1 when unparseable */
    private static function ipv6LeadingHextet0(string $addr): int
    {
        $len = \strlen($addr);
        if (0 === $len) {
            return -1;
        }
        if (1 === self::startsWithInt($addr, '::')) {
            return 0;
        }

        return self::readHextetAt($addr, 0, $len);
    }

    /** @return int second hextet, or -1 when unparseable / absent */
    private static function ipv6LeadingHextet1(string $addr): int
    {
        $len = \strlen($addr);
        if (0 === $len) {
            return -1;
        }
        if (1 === self::startsWithInt($addr, '::')) {
            return 0;
        }
        $h0End = self::hexDigitRunEnd($addr, 0, $len);
        if ($h0End <= 0 || $h0End >= $len || ':' !== $addr[$h0End]) {
            return -1;
        }
        $i = $h0End + 1;
        if ($i < $len && ':' === $addr[$i]) {
            return 0;
        }

        return self::readHextetAt($addr, $i, $len);
    }

    /** @return int index after last hex digit at/after $i, or 0 when none */
    private static function hexDigitRunEnd(string $addr, int $i, int $len): int
    {
        $n = 0;
        while ($i < $len) {
            if (self::hexDigitValue($addr[$i]) < 0) {
                break;
            }
            ++$n;
            ++$i;
            if ($n > 4) {
                return 0;
            }
        }

        return 0 === $n ? 0 : $i;
    }

    /** @return int hextet value, or -1 */
    private static function readHextetAt(string $addr, int $i, int $len): int
    {
        $val = 0;
        $n = 0;
        while ($i < $len) {
            $v = self::hexDigitValue($addr[$i]);
            if ($v < 0) {
                break;
            }
            $val = ($val << 4) | $v;
            ++$n;
            ++$i;
            if ($n > 4) {
                return -1;
            }
        }
        if (0 === $n) {
            return -1;
        }

        return $val;
    }

    /** @return int 1 when $s is dotted IPv4 text, else 0 */
    private static function isIpv4Text(string $s): int
    {
        $len = \strlen($s);
        $start = 0;
        $octets = 0;
        for ($i = 0; $i <= $len; ++$i) {
            if ($i === $len || '.' === $s[$i]) {
                if ($octets >= 4) {
                    return 0;
                }
                $octetLen = $i - $start;
                if (0 === $octetLen || $octetLen > 3) {
                    return 0;
                }
                $val = 0;
                for ($j = $start; $j < $i; ++$j) {
                    $o = \ord($s[$j]);
                    if ($o < 48 || $o > 57) {
                        return 0;
                    }
                    $val = $val * 10 + ($o - 48);
                }
                if ($val > 255) {
                    return 0;
                }
                ++$octets;
                $start = $i + 1;
            }
        }

        return 4 === $octets ? 1 : 0;
    }

    /**
     * @return int 1 when IPv4 $s must be rejected under the active flag subset
     */
    private static function ipv4RejectedByFlags(string $s, int $noPriv, int $noRes, int $globalOnly): int
    {
        $len = \strlen($s);
        $o0 = $o1 = $o2 = $o3 = 0;
        $start = 0;
        $octets = 0;
        for ($i = 0; $i <= $len; ++$i) {
            if ($i === $len || '.' === $s[$i]) {
                if ($octets >= 4) {
                    return 0;
                }
                $octetLen = $i - $start;
                if (0 === $octetLen || $octetLen > 3) {
                    return 0;
                }
                $val = 0;
                for ($j = $start; $j < $i; ++$j) {
                    $o = \ord($s[$j]);
                    if ($o < 48 || $o > 57) {
                        return 0;
                    }
                    $val = $val * 10 + ($o - 48);
                }
                if ($val > 255) {
                    return 0;
                }
                if (0 === $octets) {
                    $o0 = $val;
                } elseif (1 === $octets) {
                    $o1 = $val;
                } elseif (2 === $octets) {
                    $o2 = $val;
                } else {
                    $o3 = $val;
                }
                ++$octets;
                $start = $i + 1;
            }
        }
        if (4 !== $octets) {
            return 0;
        }

        return self::ipv4SpecialReject($o0, $o1, $o2, $o3, $noPriv, $noRes, $globalOnly);
    }

    /** @return int 1 when the octets match a special range that fails the active flags */
    private static function ipv4SpecialReject(int $ip0, int $ip1, int $ip2, int $ip3, int $noPriv, int $noRes, int $globalOnly): int
    {
        $lastPriv = 0;
        $lastRes = 0;
        $lastGlob = 0;
        $matched = 0;
        if (0 === $ip0) {
            $lastRes = 1;
            $matched = 1;
        } elseif (10 === $ip0) {
            $lastPriv = 1;
            $matched = 1;
        } elseif (127 === $ip0) {
            $lastRes = 1;
            $matched = 1;
        } elseif (169 === $ip0 && 254 === $ip1) {
            $lastRes = 1;
            $matched = 1;
        } elseif (172 === $ip0 && $ip1 >= 16 && $ip1 <= 31) {
            $lastPriv = 1;
            $matched = 1;
        } elseif (192 === $ip0 && 168 === $ip1) {
            $lastPriv = 1;
            $matched = 1;
        } elseif ($ip0 >= 240 && $ip0 <= 255) {
            $lastRes = 1;
            $matched = 1;
        } elseif (192 === $ip0 && 88 === $ip1 && 99 === $ip2) {
            $lastGlob = 1;
            $matched = 1;
        }
        if (0 === $matched) {
            return 0;
        }
        if ($noPriv && 1 === $lastPriv) {
            return 1;
        }
        if ($noRes && 1 === $lastRes) {
            return 1;
        }
        if ($globalOnly && 0 === $lastGlob) {
            return 1;
        }

        return 0;
    }

    private static function isIpv6Text(string $s): int
    {
        if ('' === $s || 0 === self::containsCharInt($s, ':')) {
            return 0;
        }
        $len = \strlen($s);
        for ($i = 0; $i < $len; ++$i) {
            $o = \ord($s[$i]);
            $ok = ($o >= 48 && $o <= 57)
                || ($o >= 65 && $o <= 70)
                || ($o >= 97 && $o <= 102)
                || 58 === $o
                || 46 === $o;
            if (!$ok) {
                return 0;
            }
        }
        if (':' === $s[0] && 0 === self::startsWithInt($s, '::')) {
            return 0;
        }
        $dbl = 0;
        for ($i = 0; $i < $len - 1; ++$i) {
            if (':' === $s[$i] && ':' === $s[$i + 1]) {
                ++$dbl;
                if ($dbl > 1) {
                    return 0;
                }
            }
        }
        $colons = 0;
        for ($i = 0; $i < $len; ++$i) {
            if (':' === $s[$i]) {
                ++$colons;
            }
        }
        if (0 === $dbl && $colons < 2) {
            return 0;
        }

        return 1;
    }

    /** @return int 0–15 for hex digit, else -1 */
    private static function hexDigitValue(string $ch): int
    {
        $o = \ord($ch);
        if ($o >= 48 && $o <= 57) {
            return $o - 48;
        }
        if ($o >= 97 && $o <= 102) {
            return $o - 87;
        }
        if ($o >= 65 && $o <= 70) {
            return $o - 55;
        }

        return -1;
    }

    /** @return int 1 when $s starts with $prefix */
    private static function startsWithInt(string $s, string $prefix): int
    {
        $pl = \strlen($prefix);
        $sl = \strlen($s);
        if ($sl < $pl) {
            return 0;
        }
        for ($i = 0; $i < $pl; ++$i) {
            if ($s[$i] !== $prefix[$i]) {
                return 0;
            }
        }

        return 1;
    }

    /** @return int 1 when $s contains $ch */
    private static function containsCharInt(string $s, string $ch): int
    {
        $len = \strlen($s);
        for ($i = 0; $i < $len; ++$i) {
            if ($s[$i] === $ch) {
                return 1;
            }
        }

        return 0;
    }
}
