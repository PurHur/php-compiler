<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * parse_url() AOT NestedJIT helper (#36382).
 * Small public methods only — NestedJIT aborts on large/private CFG graphs.
 * Byte ord() compares. php-src: ext/standard/url.c
 */
final class ParseUrlJitHelper
{
    public static function parseUrlComponent(string $url, int $component): int
    {
        // TAG_FALSE=0 TAG_NULL=1 TAG_STRING=2 TAG_INT=3 (php-src url.c / #27078).
        if (2 === $component) {
            $p = self::portOf($url);

            return $p < 0 ? 1 : 3;
        }
        // Empty user/pass are still present when @ / :@ shape says so (php-src url.c).
        if (3 === $component) {
            return self::hasUser($url) ? 2 : 1;
        }
        if (4 === $component) {
            return self::hasPass($url) ? 2 : 1;
        }
        $s = self::componentString($url, $component);
        if ('' === $s) {
            return 1;
        }

        return 2;
    }

    public static function componentString(string $url, int $component): string
    {
        // PHP_URL_* constants: SCHEME=0 HOST=1 PORT=2 USER=3 PASS=4 PATH=5 QUERY=6 FRAGMENT=7
        if (0 === $component) {
            return self::schemeOf($url);
        }
        if (1 === $component) {
            return self::hostOf($url);
        }
        if (3 === $component) {
            return self::userOf($url);
        }
        if (4 === $component) {
            return self::passOf($url);
        }
        if (5 === $component) {
            return self::pathOf($url);
        }
        if (6 === $component) {
            return self::queryOf($url);
        }
        if (7 === $component) {
            return self::fragmentOf($url);
        }

        return '';
    }

    public static function componentInt(string $url, int $component): int
    {
        if (2 !== $component) {
            return 0;
        }

        return self::portOf($url);
    }

    public static function schemeOf(string $url): string
    {
        $n = \strlen($url);
        $i = 0;
        while ($i < $n) {
            $o = \ord($url[$i]);
            $ok = ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122)
                || ($o >= 48 && $o <= 57) || 43 === $o || 45 === $o || 46 === $o;
            if (!$ok) {
                break;
            }
            ++$i;
        }
        if ($i < 1 || $i >= $n || 58 !== \ord($url[$i])) {
            return '';
        }

        return \strtolower(\substr($url, 0, $i));
    }

    public static function hostOf(string $url): string
    {
        $a = self::authorityOf($url);
        if ('' === $a) {
            return '';
        }
        $n = \strlen($a);
        $at = -1;
        $i = 0;
        while ($i < $n) {
            if (64 === \ord($a[$i])) {
                $at = $i;
            }
            ++$i;
        }
        if ($at >= 0) {
            $a = \substr($a, $at + 1);
            $n = \strlen($a);
        }
        $colon = -1;
        $i = $n - 1;
        while ($i >= 0) {
            if (58 === \ord($a[$i])) {
                $colon = $i;
                break;
            }
            --$i;
        }
        if ($colon >= 0) {
            return \substr($a, 0, $colon);
        }

        return $a;
    }

    public static function hasUser(string $url): bool
    {
        $a = self::authorityOf($url);
        $n = \strlen($a);
        $i = 0;
        while ($i < $n) {
            if (64 === \ord($a[$i])) {
                return true;
            }
            ++$i;
        }

        return false;
    }

    public static function hasPass(string $url): bool
    {
        $a = self::authorityOf($url);
        $n = \strlen($a);
        $at = -1;
        $i = 0;
        while ($i < $n) {
            if (64 === \ord($a[$i])) {
                $at = $i;
            }
            ++$i;
        }
        if ($at < 0) {
            return false;
        }
        $i = 0;
        while ($i < $at) {
            if (58 === \ord($a[$i])) {
                return true;
            }
            ++$i;
        }

        return false;
    }

    public static function userOf(string $url): string
    {
        if (!self::hasUser($url)) {
            return '';
        }
        $a = self::authorityOf($url);
        $n = \strlen($a);
        $at = 0;
        $i = 0;
        while ($i < $n) {
            if (64 === \ord($a[$i])) {
                $at = $i;
            }
            ++$i;
        }
        $ui = \substr($a, 0, $at);
        $n = \strlen($ui);
        $i = 0;
        while ($i < $n) {
            if (58 === \ord($ui[$i])) {
                return \substr($ui, 0, $i);
            }
            ++$i;
        }

        return $ui;
    }

    public static function passOf(string $url): string
    {
        if (!self::hasPass($url)) {
            return '';
        }
        $a = self::authorityOf($url);
        $n = \strlen($a);
        $at = 0;
        $i = 0;
        while ($i < $n) {
            if (64 === \ord($a[$i])) {
                $at = $i;
            }
            ++$i;
        }
        $ui = \substr($a, 0, $at);
        $n = \strlen($ui);
        $i = 0;
        while ($i < $n) {
            if (58 === \ord($ui[$i])) {
                return \substr($ui, $i + 1);
            }
            ++$i;
        }

        return '';
    }

    public static function pathOf(string $url): string
    {
        $rest = self::afterSchemeOf($url);
        $n = \strlen($rest);
        if ($n >= 2 && 47 === \ord($rest[0]) && 47 === \ord($rest[1])) {
            $rest = \substr($rest, 2);
            $n = \strlen($rest);
            $i = 0;
            while ($i < $n) {
                $o = \ord($rest[$i]);
                if (47 === $o || 63 === $o || 35 === $o) {
                    break;
                }
                ++$i;
            }
            $rest = \substr($rest, $i);
            $n = \strlen($rest);
        }
        $i = 0;
        while ($i < $n) {
            $o = \ord($rest[$i]);
            if (63 === $o || 35 === $o) {
                break;
            }
            ++$i;
        }

        return \substr($rest, 0, $i);
    }

    public static function queryOf(string $url): string
    {
        $rest = self::pathTailOf($url);
        $n = \strlen($rest);
        $q = -1;
        $i = 0;
        while ($i < $n) {
            if (63 === \ord($rest[$i])) {
                $q = $i;
                break;
            }
            ++$i;
        }
        if ($q < 0) {
            return '';
        }
        $rest = \substr($rest, $q + 1);
        $n = \strlen($rest);
        $i = 0;
        while ($i < $n) {
            if (35 === \ord($rest[$i])) {
                break;
            }
            ++$i;
        }

        return \substr($rest, 0, $i);
    }

    public static function fragmentOf(string $url): string
    {
        $rest = self::pathTailOf($url);
        $n = \strlen($rest);
        $i = 0;
        while ($i < $n) {
            if (35 === \ord($rest[$i])) {
                return \substr($rest, $i + 1);
            }
            ++$i;
        }

        return '';
    }

    public static function authorityOf(string $url): string
    {
        $rest = self::afterSchemeOf($url);
        $n = \strlen($rest);
        if ($n < 2 || 47 !== \ord($rest[0]) || 47 !== \ord($rest[1])) {
            return '';
        }
        $rest = \substr($rest, 2);
        $n = \strlen($rest);
        $i = 0;
        while ($i < $n) {
            $o = \ord($rest[$i]);
            if (47 === $o || 63 === $o || 35 === $o) {
                break;
            }
            ++$i;
        }

        return \substr($rest, 0, $i);
    }

    public static function afterSchemeOf(string $url): string
    {
        $s = self::schemeOf($url);
        if ('' === $s) {
            return $url;
        }

        return \substr($url, \strlen($s) + 1);
    }

    public static function pathTailOf(string $url): string
    {
        $rest = self::afterSchemeOf($url);
        $n = \strlen($rest);
        if ($n >= 2 && 47 === \ord($rest[0]) && 47 === \ord($rest[1])) {
            $rest = \substr($rest, 2);
            $n = \strlen($rest);
            $i = 0;
            while ($i < $n) {
                $o = \ord($rest[$i]);
                if (47 === $o || 63 === $o || 35 === $o) {
                    break;
                }
                ++$i;
            }

            return \substr($rest, $i);
        }

        return $rest;
    }

    public static function portOf(string $url): int
    {
        $a = self::authorityOf($url);
        $n = \strlen($a);
        $at = -1;
        $i = 0;
        while ($i < $n) {
            if (64 === \ord($a[$i])) {
                $at = $i;
            }
            ++$i;
        }
        if ($at >= 0) {
            $a = \substr($a, $at + 1);
            $n = \strlen($a);
        }
        $colon = -1;
        $i = $n - 1;
        while ($i >= 0) {
            if (58 === \ord($a[$i])) {
                $colon = $i;
                break;
            }
            --$i;
        }
        if ($colon < 0) {
            return -1;
        }
        $ps = \substr($a, $colon + 1);
        $pn = \strlen($ps);
        if ($pn < 1 || $pn > 5) {
            return -1;
        }
        $p = 0;
        while ($p < $pn) {
            $o = \ord($ps[$p]);
            if ($o < 48 || $o > 57) {
                return -1;
            }
            ++$p;
        }
        $v = (int) $ps;
        if ($v > 65535) {
            return -1;
        }

        return $v;
    }

    public static function lastString(): string
    {
        return '';
    }

    public static function lastInt(): int
    {
        return 0;
    }

    /**
     * Host/VM unit-test path only — not NestedJIT'd (not in COMPILED_HELPERS).
     *
     * @return array<string, int|string>|null
     */
    public static function parseUrlAssoc(string $url): ?array
    {
        $result = VmString::parseUrl($url, -1);
        if (false === $result) {
            return null;
        }
        if (!\is_array($result)) {
            throw new \LogicException('parse_url() assoc expected array');
        }

        return $result;
    }

    public static function resetForTest(): void
    {
    }
}
