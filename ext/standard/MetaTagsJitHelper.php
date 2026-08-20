<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * get_meta_tags() for compiled JIT/AOT modules (#9338, #33035, php-in-PHP).
 *
 * Read via `@file_get_contents` → NestedJIT whitelist → {@see JitFileGetContentsLibc}
 * (peer FileGetContentsJitHelper #29833 / MimeContentTypeJitHelper #33034).
 *
 * Parse logic is inlined in this file: NestedJIT helper compile only covers HELPER_PATH
 * (cross-file VmMetaTags returns null under AOT). Keep heuristics in sync with
 * {@see VmMetaTags} (VM SSOT). Avoid preg — NestedJIT has no helper-runtime preg (#27520).
 * Byte walks avoid NestedJIT-hostile strlen/strpos/substr (peer ExplodeJitHelper #27660).
 *
 * Return type is `array` (not HashTable): NestedJIT maps class HashTable to object ABI
 * (peer HashAlgosJitHelper #20652). `array` → `__hashtable__*`.
 * Thin standalone AOT with compile-time path literals folds via VmMetaTags in
 * {@see get_meta_tags::call} (#33035) — NestedJIT string→array under thin AOT is unsafe.
 *
 * php-src: ext/standard/php_meta_tags.c — PHP_FUNCTION(get_meta_tags)
 */
final class MetaTagsJitHelper
{
    /**
     * @return array<string, string>|null null when file read fails
     */
    public static function getMetaTags(string $filename, bool $useIncludePath): ?array
    {
        $html = @\file_get_contents($filename);
        if (false === $html && $useIncludePath) {
            $html = self::readViaIncludePath($filename);
        }
        if (false === $html) {
            return null;
        }

        $result = [];
        $pos = 0;
        while (isset($html[$pos])) {
            $metaPos = self::findCi($html, '<meta', $pos);
            if ($metaPos < 0) {
                break;
            }
            $gtPos = self::findByte($html, '>', $metaPos);
            if ($gtPos < 0) {
                break;
            }
            $tag = self::slice($html, $metaPos, $gtPos - $metaPos + 1);
            $name = self::extractAttribute($tag, 'name');
            $content = self::extractAttribute($tag, 'content');
            if (null !== $name && null !== $content) {
                $result[self::normalizeMetaName($name)] = $content;
            }
            $pos = $gtPos + 1;
        }

        return $result;
    }

    /** @return string|false */
    private static function readViaIncludePath(string $filename): string|false
    {
        if ('' === $filename) {
            return false;
        }
        if ('/' === $filename[0] || (isset($filename[1]) && ':' === $filename[1])) {
            return false;
        }
        $cwd = @\getcwd();
        if (false === $cwd || '' === $cwd) {
            return false;
        }
        $html = @\file_get_contents($cwd.'/'.$filename);

        return false !== $html ? $html : false;
    }

    private static function normalizeMetaName(string $name): string
    {
        $normalized = '';
        $i = 0;
        while (isset($name[$i])) {
            $ch = $name[$i];
            $lo = self::lowerByte($ch);
            $normalized .= ('.' === $lo || ' ' === $lo) ? '_' : $lo;
            ++$i;
        }

        return $normalized;
    }

    private static function extractAttribute(string $tag, string $attr): ?string
    {
        $searchFrom = 0;
        $needleLen = self::byteLen($attr);
        while (isset($tag[$searchFrom])) {
            $at = self::findCi($tag, $attr, $searchFrom);
            if ($at < 0) {
                return null;
            }
            if ($at > 0 && self::isWordByte($tag[$at - 1])) {
                $searchFrom = $at + 1;
                continue;
            }
            $i = $at + $needleLen;
            while (isset($tag[$i]) && self::isSpace($tag[$i])) {
                ++$i;
            }
            if (!isset($tag[$i]) || '=' !== $tag[$i]) {
                $searchFrom = $at + 1;
                continue;
            }
            ++$i;
            while (isset($tag[$i]) && self::isSpace($tag[$i])) {
                ++$i;
            }
            if (!isset($tag[$i])) {
                return '';
            }
            $quote = $tag[$i];
            if ('"' === $quote || "'" === $quote) {
                ++$i;
                $end = self::findByte($tag, $quote, $i);
                if ($end < 0) {
                    return self::slice($tag, $i, self::byteLen($tag) - $i);
                }

                return self::slice($tag, $i, $end - $i);
            }
            $start = $i;
            while (isset($tag[$i])) {
                $ch = $tag[$i];
                if (' ' === $ch || '>' === $ch || '"' === $ch || "'" === $ch) {
                    break;
                }
                ++$i;
            }

            return self::slice($tag, $start, $i - $start);
        }

        return null;
    }

    private static function isWordByte(string $ch): bool
    {
        return ('a' <= $ch && $ch <= 'z')
            || ('A' <= $ch && $ch <= 'Z')
            || ('0' <= $ch && $ch <= '9')
            || '_' === $ch;
    }

    private static function isSpace(string $ch): bool
    {
        return ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch;
    }

    private static function lowerByte(string $ch): string
    {
        if ('A' <= $ch && $ch <= 'Z') {
            return \chr(\ord($ch) + 32);
        }

        return $ch;
    }

    private static function byteLen(string $s): int
    {
        $n = 0;
        while (isset($s[$n])) {
            ++$n;
        }

        return $n;
    }

    private static function slice(string $s, int $start, int $len): string
    {
        if ($len <= 0) {
            return '';
        }
        $out = '';
        $i = $start;
        $end = $start + $len;
        while ($i < $end && isset($s[$i])) {
            $out .= $s[$i];
            ++$i;
        }

        return $out;
    }

    private static function findByte(string $haystack, string $needle, int $offset): int
    {
        $i = $offset;
        while (isset($haystack[$i])) {
            if ($haystack[$i] === $needle) {
                return $i;
            }
            ++$i;
        }

        return -1;
    }

    private static function findCi(string $haystack, string $needle, int $offset): int
    {
        $nlen = self::byteLen($needle);
        if ($nlen <= 0) {
            return $offset;
        }
        $i = $offset;
        while (isset($haystack[$i])) {
            $j = 0;
            $k = $i;
            while ($j < $nlen && isset($haystack[$k])
                && self::lowerByte($haystack[$k]) === self::lowerByte($needle[$j])) {
                ++$j;
                ++$k;
            }
            if ($j === $nlen) {
                return $i;
            }
            ++$i;
        }

        return -1;
    }
}
