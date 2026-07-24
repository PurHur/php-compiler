<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * FILTER_VALIDATE_EMAIL subset — separate compile unit for Nested JIT/AOT (#9860, #22826).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_email
 *
 * Keep this unit free of VmFilter / Frame so FilterEmailJitHelper emit stays lean.
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
        $len = \strlen($s);
        if (0 === $len || $len > 320) {
            return false;
        }
        $at = \strpos($s, '@');
        if (false === $at || $at !== \strrpos($s, '@')) {
            return false;
        }
        if (0 === $at || $at === $len - 1) {
            return false;
        }
        $local = \substr($s, 0, $at);
        $domain = \substr($s, $at + 1);
        if ('' === $local || '' === $domain || !\str_contains($domain, '.')) {
            return false;
        }
        $unicode = 0 !== ($flags & self::FLAG_EMAIL_UNICODE);
        if (!self::isLocalPart($local, $unicode) || !self::isDomainPart($domain)) {
            return false;
        }

        return true;
    }

    private static function isLocalPart(string $local, bool $unicode): bool
    {
        if ($unicode) {
            return (bool) \preg_match('/^[\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]+$/u', $local);
        }

        $len = \strlen($local);
        for ($i = 0; $i < $len; ++$i) {
            if (!self::isLocalChar($local[$i])) {
                return false;
            }
        }

        return true;
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
