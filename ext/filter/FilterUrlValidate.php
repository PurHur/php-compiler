<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * FILTER_VALIDATE_URL subset — NestedJIT/AOT-safe unit (#27206, peer EMAIL #27068).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_url
 *
 * Keep free of VmFilter / `\preg_match` / by-ref / `"\0"` / bool returns.
 * Host SSOT for compile-time fold: {@see VmFilter::isValidUrlSubset()}.
 */
final class FilterUrlValidate
{
    private const FLAG_PATH_REQUIRED = 0x040000;

    private const FLAG_QUERY_REQUIRED = 0x080000;

    public static function isValid(string $s, int $flags = 0): bool
    {
        return 1 === self::isValidInt($s, $flags);
    }

    public static function isValidInt(string $s, int $flags = 0): int
    {
        $len = \strlen($s);
        if (0 === $len) {
            return 0;
        }
        for ($i = 0; $i < $len; ++$i) {
            $o = \ord($s[$i]);
            if ($o < 0x20 || 0x7f === $o) {
                return 0;
            }
        }

        if (\str_starts_with($s, 'https://')) {
            return self::validateAuthorityUrl($s, 8, $flags, 1);
        }
        if (\str_starts_with($s, 'http://')) {
            return self::validateAuthorityUrl($s, 7, $flags, 1);
        }
        if (\str_starts_with($s, 'ftp://')) {
            return self::validateAuthorityUrl($s, 6, $flags, 0);
        }
        if (\str_starts_with($s, 'mailto:') || \str_starts_with($s, 'news:') || \str_starts_with($s, 'file:')) {
            if (0 !== ($flags & self::FLAG_PATH_REQUIRED)) {
                // path after scheme for these is unusual; match VmFilter isset(path)
                return 0;
            }
            if (0 !== ($flags & self::FLAG_QUERY_REQUIRED) && !\str_contains($s, '?')) {
                return 0;
            }

            return 1;
        }

        return 0;
    }

    /**
     * @param int $checkHttpHost 1=apply http(s) host rules
     */
    private static function validateAuthorityUrl(string $s, int $prefixLen, int $flags, int $checkHttpHost): int
    {
        $len = \strlen($s);
        if ($len <= $prefixLen) {
            return 0;
        }
        $hostEnd = $len;
        $pathStart = -1;
        $queryStart = -1;
        for ($i = $prefixLen; $i < $len; ++$i) {
            $ch = $s[$i];
            if ('#' === $ch) {
                $hostEnd = $i;
                break;
            }
            if ('?' === $ch) {
                $hostEnd = $i;
                $queryStart = $i;
                break;
            }
            if ('/' === $ch) {
                $hostEnd = $i;
                $pathStart = $i;
                // continue scan for query after path
                for ($j = $i + 1; $j < $len; ++$j) {
                    if ('#' === $s[$j]) {
                        break;
                    }
                    if ('?' === $s[$j]) {
                        $queryStart = $j;
                        break;
                    }
                }
                break;
            }
        }
        $host = \substr($s, $prefixLen, $hostEnd - $prefixLen);
        if ('' === $host) {
            return 0;
        }
        // userinfo
        $at = \strrpos($host, '@');
        if (false !== $at) {
            $host = \substr($host, $at + 1);
        }
        // port
        if ('' !== $host && '[' !== $host[0]) {
            $colon = \strrpos($host, ':');
            if (false !== $colon) {
                $port = \substr($host, $colon + 1);
                $host = \substr($host, 0, $colon);
                $plen = \strlen($port);
                for ($i = 0; $i < $plen; ++$i) {
                    $o = \ord($port[$i]);
                    if ($o < 48 || $o > 57) {
                        return 0;
                    }
                }
            }
        }
        if ('' === $host) {
            return 0;
        }
        if (1 === $checkHttpHost && 0 === self::isValidHttpHost($host)) {
            return 0;
        }
        if (0 !== ($flags & self::FLAG_PATH_REQUIRED) && -1 === $pathStart) {
            return 0;
        }
        if (0 !== ($flags & self::FLAG_QUERY_REQUIRED) && -1 === $queryStart) {
            return 0;
        }

        return 1;
    }

    private static function isValidHttpHost(string $host): int
    {
        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $innerLen = \strlen($host) - 2;
            if ($innerLen < 1) {
                return 0;
            }
            for ($i = 1; $i < \strlen($host) - 1; ++$i) {
                $o = \ord($host[$i]);
                $ok = ($o >= 48 && $o <= 57)
                    || ($o >= 65 && $o <= 70)
                    || ($o >= 97 && $o <= 102)
                    || 58 === $o
                    || 46 === $o;
                if (!$ok) {
                    return 0;
                }
            }

            return 1;
        }
        if (1 === self::isIpv4($host)) {
            return 1;
        }

        return self::isHostname($host);
    }

    private static function isIpv4(string $host): int
    {
        $parts = \explode('.', $host);
        if (4 !== \count($parts)) {
            return 0;
        }
        $n = \count($parts);
        for ($pi = 0; $pi < $n; ++$pi) {
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
        }

        return 1;
    }

    private static function isHostname(string $host): int
    {
        $len = \strlen($host);
        if ($len > 0 && '.' === $host[$len - 1]) {
            --$len;
        }
        if ($len < 1 || $len > 253) {
            return 0;
        }
        $labels = \explode('.', \substr($host, 0, $len));
        $count = \count($labels);
        for ($i = 0; $i < $count; ++$i) {
            $label = $labels[$i];
            $llen = \strlen($label);
            if (0 === $llen || $llen > 63) {
                return 0;
            }
            $o0 = \ord($label[0]);
            if (0 === self::isAlnumOrd($o0)) {
                return 0;
            }
            $oLast = \ord($label[$llen - 1]);
            if (0 === self::isAlnumOrd($oLast)) {
                return 0;
            }
            for ($j = 0; $j < $llen; ++$j) {
                $ch = $label[$j];
                if ('-' === $ch) {
                    continue;
                }
                if (0 === self::isAlnumOrd(\ord($ch))) {
                    return 0;
                }
            }
        }

        return 1;
    }

    private static function isAlnumOrd(int $o): int
    {
        if (($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90) || ($o >= 48 && $o <= 57)) {
            return 1;
        }

        return 0;
    }
}
