<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * FILTER_VALIDATE_IP — NestedJIT/AOT-safe unit (#27207, peer EMAIL #27068 / URL #27206).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_ip
 *
 * Keep free of VmFilter / VmInetPure / `\preg_match` / by-ref / `"\0"` / bool NestedJIT returns.
 * Host SSOT for compile-time fold: {@see VmFilter::isValidIpAddress()}.
 */
final class FilterIpValidate
{
    private const FLAG_IPV4 = 0x00100000;

    private const FLAG_IPV6 = 0x00200000;

    private const FLAG_NO_RES_RANGE = 0x00400000;

    private const FLAG_NO_PRIV_RANGE = 0x00800000;

    private const FLAG_GLOBAL_RANGE = 0x10000000;

    private static int $o0 = 0;

    private static int $o1 = 0;

    private static int $o2 = 0;

    private static int $o3 = 0;

    private static int $lastPriv = 0;

    private static int $lastRes = 0;

    private static int $lastGlob = 0;

    public static function isValid(string $s, int $flags = 0): bool
    {
        return 1 === self::isValidInt($s, $flags);
    }

    public static function isValidInt(string $s, int $flags = 0): int
    {
        if ('' === $s) {
            return 0;
        }
        $addr = $s;
        if (\str_starts_with($s, '[') && \str_ends_with($s, ']')) {
            $addr = \substr($s, 1, \strlen($s) - 2);
        }

        $isV4 = self::parseIpv4($addr);
        $isV6 = 0;
        if (0 === $isV4) {
            $isV6 = self::isIpv6Text($addr);
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
            if (1 === self::ipv4SpecialFlags()) {
                if ($noPriv && 1 === self::$lastPriv) {
                    return 0;
                }
                if ($noRes && 1 === self::$lastRes) {
                    return 0;
                }
                if ($globalOnly && 0 === self::$lastGlob) {
                    return 0;
                }
            }
        }
        if (($noPriv || $noRes || $globalOnly) && 1 === $isV6) {
            if ($noRes && ('::1' === $addr || '::' === $addr)) {
                return 0;
            }
        }

        return 1;
    }

    /** @return int 1=parsed into self::$o0..$o3 */
    private static function parseIpv4(string $s): int
    {
        $parts = \explode('.', $s);
        if (4 !== \count($parts)) {
            return 0;
        }
        for ($pi = 0; $pi < 4; ++$pi) {
            $octet = $parts[$pi];
            $olen = \strlen($octet);
            if (0 === $olen || $olen > 3) {
                return 0;
            }
            $val = 0;
            for ($i = 0; $i < $olen; ++$i) {
                $o = \ord($octet[$i]);
                if ($o < 48 || $o > 57) {
                    return 0;
                }
                $val = $val * 10 + ($o - 48);
            }
            if ($val > 255) {
                return 0;
            }
            if (0 === $pi) {
                self::$o0 = $val;
            } elseif (1 === $pi) {
                self::$o1 = $val;
            } elseif (2 === $pi) {
                self::$o2 = $val;
            } else {
                self::$o3 = $val;
            }
        }

        return 1;
    }

    private static function isIpv6Text(string $s): int
    {
        if ('' === $s || !\str_contains($s, ':')) {
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
        if (':' === $s[0] && !\str_starts_with($s, '::')) {
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

    /** @return int 1 when address matches a special-purpose block */
    private static function ipv4SpecialFlags(): int
    {
        self::$lastPriv = 0;
        self::$lastRes = 0;
        self::$lastGlob = 0;
        $ip0 = self::$o0;
        $ip1 = self::$o1;
        $ip2 = self::$o2;
        if (0 === $ip0) {
            self::$lastRes = 1;
        } elseif (10 === $ip0) {
            self::$lastPriv = 1;
        } elseif (127 === $ip0) {
            self::$lastRes = 1;
        } elseif (169 === $ip0 && 254 === $ip1) {
            self::$lastRes = 1;
        } elseif (172 === $ip0 && $ip1 >= 16 && $ip1 <= 31) {
            self::$lastPriv = 1;
        } elseif (192 === $ip0 && 168 === $ip1) {
            self::$lastPriv = 1;
        } elseif ($ip0 >= 240 && $ip0 <= 255) {
            self::$lastRes = 1;
        } elseif (192 === $ip0 && 88 === $ip1 && 99 === $ip2) {
            self::$lastGlob = 1;
        } else {
            return 0;
        }

        return 1;
    }
}
