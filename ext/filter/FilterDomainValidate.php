<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * FILTER_VALIDATE_DOMAIN — NestedJIT/AOT-safe unit (#35029, peer EMAIL #27068 / URL #27206).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_domain[_ex]
 *
 * Keep free of VmFilter / `\preg_match` / NestedJIT `?string` / bool returns /
 * `"\0"` sentinels / string-offset compound bool temps.
 * Host SSOT for compile-time fold: {@see VmFilter::isValidDomain()}.
 */
final class FilterDomainValidate
{
    /** php-src FILTER_FLAG_HOSTNAME */
    public const FLAG_HOSTNAME = 0x100000;

    public static function isValid(string $host, int $flags = 0): bool
    {
        return 1 === self::isValidInt($host, $flags);
    }

    /**
     * NestedJIT-safe 0/1 result for thin AOT dynamic bridges (#26853 / #35029).
     */
    public static function isValidInt(string $host, int $flags = 0): int
    {
        $hostname = (0 !== ($flags & self::FLAG_HOSTNAME)) ? 1 : 0;
        $len = \strlen($host);
        $end = $len;

        // Ignore trailing dot for length / scan bound (char still peekable past $end).
        if ($len > 0 && 46 === \ord($host[$len - 1])) { // '.'
            $end = $len - 1;
            $len = $end;
        }

        if ($len > 253) {
            return 0;
        }

        // "" → success in loose mode; "." (stripped to empty) → fail via first-char '.'.
        if (0 === $len) {
            return (0 === \strlen($host) && 0 === $hostname) ? 1 : 0;
        }

        $firstOrd = \ord($host[0]);
        if (46 === $firstOrd || (1 === $hostname && 0 === self::isAlnumOrd($firstOrd))) {
            return 0;
        }

        $hostLen = \strlen($host);
        $labelLen = 1;
        for ($s = 0; $s < $end; ++$s) {
            $chOrd = \ord($host[$s]);
            $hasNext = (($s + 1) < $hostLen) ? 1 : 0;
            $nextOrd = (1 === $hasNext) ? \ord($host[$s + 1]) : 0;
            if (46 === $chOrd) { // '.'
                // Reject ".." and (HOSTNAME) labels that do not start/end alnum.
                if (0 === $hasNext || 46 === $nextOrd
                    || (1 === $hostname && (
                        0 === self::isAlnumOrd(\ord($host[$s - 1]))
                        || 0 === self::isAlnumOrd($nextOrd)
                    ))) {
                    return 0;
                }
                $labelLen = 1;
                continue;
            }

            // Label length > 63, or (HOSTNAME) char not alnum/`-` (hyphen not at EOL).
            if ($labelLen > 63
                || (1 === $hostname
                    && (45 !== $chOrd || 0 === $hasNext)
                    && 0 === self::isAlnumOrd($chOrd))) {
                return 0;
            }
            ++$labelLen;
        }

        return 1;
    }

    /** php-src isalnum((unsigned char)ch) for domain label checks. */
    private static function isAlnumOrd(int $o): int
    {
        if (($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90) || ($o >= 48 && $o <= 57)) {
            return 1;
        }

        return 0;
    }
}
