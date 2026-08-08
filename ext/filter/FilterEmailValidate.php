<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * FILTER_VALIDATE_EMAIL subset — separate compile unit for Nested JIT/AOT (#9860, #22826).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_email
 *
 * Keep this unit free of VmFilter / Frame / `\preg_match` so NestedJIT stays lean
 * and thin AOT does not emit `__compiler_preg_match` (#27068).
 */
final class FilterEmailValidate
{
    /** php-src FILTER_FLAG_EMAIL_UNICODE — local-part only; domain stays ASCII/punycode. */
    public const FLAG_EMAIL_UNICODE = 0x100000;

    /**
     * Practical email subset matching php-src's routeable-domain regex arm.
     */
    public static function isValid(string $s, int $flags = 0): bool
    {
        return 1 === self::isValidInt($s, $flags);
    }

    /**
     * NestedJIT-safe 0/1 result for thin AOT dynamic bridges (#27068).
     * Bool / `?string` NestedJIT returns are corrupt under thin AOT (#26853); int matches FilterInt.
     */
    public static function isValidInt(string $s, int $flags = 0): int
    {
        $len = \strlen($s);
        if (0 === $len || $len > 320) {
            return 0;
        }
        $at = \strpos($s, '@');
        if (false === $at || $at !== \strrpos($s, '@')) {
            return 0;
        }
        if (0 === $at || $at === $len - 1) {
            return 0;
        }
        $local = \substr($s, 0, $at);
        $domain = \substr($s, $at + 1);
        if ('' === $local || '' === $domain) {
            return 0;
        }
        $unicode = 0 !== ($flags & self::FLAG_EMAIL_UNICODE);
        if (!self::isLocalPart($local, $unicode)) {
            return 0;
        }
        // php-src domain arm: DNS labels OR bracketed IPv4 / IPv6: literal (#29045).
        if (self::isDomainLiteral($domain)) {
            return 1;
        }
        if (!\str_contains($domain, '.') || !self::isDomainPart($domain)) {
            return 0;
        }

        return 1;
    }

    private static function isLocalPart(string $local, bool $unicode): bool
    {
        $len = \strlen($local);
        if (0 === $len) {
            return false;
        }
        // php-src atom / Michael Rushton regex: no leading, trailing, or consecutive '.' (#29014).
        // Quoted-string locals (which may embed '..') are outside this NestedJIT subset.
        if ('.' === $local[0] || '.' === $local[$len - 1] || \str_contains($local, '..')) {
            return false;
        }
        for ($i = 0; $i < $len; ++$i) {
            $o = \ord($local[$i]);
            if ($unicode && $o >= 0x80) {
                // Approx php-src `\p{L}\p{N}` without `\preg_match` — NestedJIT of
                // that call emits `__compiler_preg_match` with no AOT provider (#27068).
                continue;
            }
            if (!self::isLocalChar($local[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * php-src email regex domain-literal arm: `[IPv4]` or `[IPv6:addr]` (`/i` → tag caseless).
     * Bare `[::1]` is rejected — the `IPv6:` tag is required (#29045).
     */
    private static function isDomainLiteral(string $domain): bool
    {
        $len = \strlen($domain);
        if ($len < 3 || '[' !== $domain[0] || ']' !== $domain[$len - 1]) {
            return false;
        }
        $inner = \substr($domain, 1, $len - 2);
        if ('' === $inner) {
            return false;
        }
        // FILTER_FLAG_IPV4 / FILTER_FLAG_IPV6 — SSOT in FilterIpValidate (NestedJIT-safe).
        $flagIpv4 = 0x00100000;
        $flagIpv6 = 0x00200000;
        if (\strlen($inner) >= 5 && 'ipv6:' === \strtolower(\substr($inner, 0, 5))) {
            $addr = \substr($inner, 5);

            return 1 === FilterIpValidate::isValidInt($addr, $flagIpv6);
        }

        return 1 === FilterIpValidate::isValidInt($inner, $flagIpv4);
    }

    /**
     * Domain labels: `(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*` (caseless), no empty /
     * leading/trailing `.`, max 63 octets/label; final label letter-leading unless xn-- (#22826).
     */
    private static function isDomainPart(string $domain): bool
    {
        if ('' === $domain || \str_starts_with($domain, '.') || \str_ends_with($domain, '.')) {
            return false;
        }

        $labels = \explode('.', $domain);
        $count = \count($labels);
        if ($count < 2) {
            return false;
        }

        for ($i = 0; $i < $count; ++$i) {
            $label = $labels[$i];
            $len = \strlen($label);
            if (0 === $len || $len > 63) {
                return false;
            }
            if (!self::isDomainLabel($label, $i === $count - 1)) {
                return false;
            }
        }

        return true;
    }

    private static function isDomainLabel(string $label, bool $isTld): bool
    {
        $label = \strtolower($label);
        $len = \strlen($label);
        $i = 0;
        if ($len >= 4 && 'xn--' === \substr($label, 0, 4)) {
            $i = 4;
            if ($i >= $len) {
                return false;
            }
        } elseif ($isTld) {
            // NestedJIT: compare via ord() — string >=/'a' is TYPE_GREATER_OR_EQUAL unsupported.
            $o = \ord($label[0]);
            if ($o < 97 || $o > 122) {
                return false;
            }
        }

        $sawAlnum = false;
        $needAlnumAfterHyphen = false;
        for (; $i < $len; ++$i) {
            $o = \ord($label[$i]);
            if (($o >= 97 && $o <= 122) || ($o >= 48 && $o <= 57)) {
                $sawAlnum = true;
                $needAlnumAfterHyphen = false;
                continue;
            }
            if (45 === $o) { // '-'
                if (!$sawAlnum || $i === $len - 1) {
                    return false;
                }
                $needAlnumAfterHyphen = true;
                continue;
            }

            return false;
        }

        return $sawAlnum && !$needAlnumAfterHyphen;
    }

    private static function isLocalChar(string $ch): bool
    {
        $o = \ord($ch);
        if (($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90) || ($o >= 48 && $o <= 57)) {
            return true;
        }

        return \str_contains('.!#$%&\'*+/=?^_`{|}~-', $ch);
    }
}
