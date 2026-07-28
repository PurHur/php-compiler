<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_decode() NestedJIT assoc runtime helper (#9359, #20829, #24137).
 *
 * NestedJIT constraints (verified under AOT NestedJIT for #24137):
 * - VmJsonFormat ExternalMethod → null; no string-key PHP arrays; no HT* return
 * - no statics / by-ref / PHP array returns
 * - `(int) substr` → 0; use ord accumulation
 * - keep parseObject + storeAtKeyFlat small (large bodies segfault / blank)
 *
 * Supports assoc objects with string keys, int values, and one-level int arrays.
 * php-src: ext/json/php_json.c — php_json_decode_ex
 */
final class JsonDecodeJitHelper
{
    public const TAG_NULL = 0;

    public const TAG_BOOL = 1;

    public const TAG_INT = 2;

    public const TAG_FLOAT = 3;

    public const TAG_STRING = 4;

    public const TAG_ARRAY = 5;

    /** Unused by LLVM bridge (tag peek is LLVM-side). */
    public static function resultTag(string $payload): int
    {
        $len = \strlen($payload);
        $pos = self::skipWs($payload, $len, 0);
        if ($pos >= $len) {
            return self::TAG_NULL;
        }
        $c = $payload[$pos];
        if ('{' === $c || '[' === $c) {
            return self::TAG_ARRAY;
        }
        if ('"' === $c) {
            return self::TAG_STRING;
        }
        if ('t' === $c || 'f' === $c) {
            return self::TAG_BOOL;
        }
        if ('n' === $c) {
            return self::TAG_NULL;
        }
        if ('-' === $c || self::isDigit($c)) {
            return self::TAG_INT;
        }

        return self::TAG_NULL;
    }

    /**
     * Fill caller-owned native HT. Bridge allocates HT (#24137 / ParseStr #13827).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function decodeInto(int $destPtr, string $payload): int
    {
        if ($destPtr <= 0) {
            return 0;
        }
        $len = \strlen($payload);
        $pos = self::skipWs($payload, $len, 0);
        if ($pos >= $len || '{' !== $payload[$pos]) {
            return 0;
        }
        $pos = self::parseObject($destPtr, $payload, $len, $pos);
        if ($pos < 0) {
            return 0;
        }
        $pos = self::skipWs($payload, $len, $pos);

        return $pos === $len ? 1 : 0;
    }

    public static function decodeInt(string $payload): int
    {
        $len = \strlen($payload);
        $pos = self::skipWs($payload, $len, 0);
        if ($pos >= $len) {
            return 0;
        }
        $c = $payload[$pos];
        $neg = false;
        if ('-' === $c) {
            $neg = true;
            ++$pos;
            if ($pos >= $len) {
                return 0;
            }
            $c = $payload[$pos];
        }
        if (!self::isDigit($c)) {
            return 0;
        }
        $val = \ord($c) - 48;
        ++$pos;
        while ($pos < $len && self::isDigit($payload[$pos])) {
            $digit = \ord($payload[$pos]) - 48;
            $val = $val * 10;
            $val = $val + $digit;
            ++$pos;
        }
        if ($pos < $len && ('.' === $payload[$pos] || 'e' === $payload[$pos] || 'E' === $payload[$pos])) {
            return 0;
        }
        $pos = self::skipWs($payload, $len, $pos);
        if ($pos !== $len) {
            return 0;
        }
        if ($neg) {
            $val = 0 - $val;
        }

        return $val;
    }

    public static function decodeBool(string $payload): bool
    {
        $len = \strlen($payload);
        $pos = self::skipWs($payload, $len, 0);
        if ($pos < $len && 't' === $payload[$pos]) {
            $pos = self::eat($payload, $len, $pos, 'true');

            return $pos >= 0 && self::skipWs($payload, $len, $pos) === $len;
        }
        if ($pos < $len && 'f' === $payload[$pos]) {
            $pos = self::eat($payload, $len, $pos, 'false');

            return false;
        }

        return false;
    }

    public static function decodeFloat(string $payload): float
    {
        $len = \strlen($payload);
        $pos = self::skipWs($payload, $len, 0);
        $start = $pos;
        while ($pos < $len && $payload[$pos] !== ',' && $payload[$pos] !== '}' && $payload[$pos] !== ']' && $payload[$pos] !== ' ') {
            ++$pos;
        }
        if ($pos === $start) {
            return 0.0;
        }
        $slice = \substr($payload, $start, $pos - $start);
        $pos = self::skipWs($payload, $len, $pos);
        if ($pos !== $len) {
            return 0.0;
        }

        return (float) $slice;
    }

    public static function decodeString(string $payload): string
    {
        $len = \strlen($payload);
        $pos = self::skipWs($payload, $len, 0);
        $end = self::stringEnd($payload, $len, $pos);
        if ($end < 0) {
            return '';
        }
        $s = \substr($payload, $pos + 1, $end - $pos - 1);
        $pos = self::skipWs($payload, $len, $end + 1);
        if ($pos !== $len) {
            return '';
        }

        return $s;
    }

    private static function isDigit(string $c): bool
    {
        return '0' === $c || '1' === $c || '2' === $c || '3' === $c || '4' === $c
            || '5' === $c || '6' === $c || '7' === $c || '8' === $c || '9' === $c;
    }

    private static function skipWs(string $json, int $len, int $pos): int
    {
        while ($pos < $len) {
            $c = $json[$pos];
            if (' ' !== $c && "\t" !== $c && "\n" !== $c && "\r" !== $c) {
                return $pos;
            }
            ++$pos;
        }

        return $pos;
    }

    private static function eat(string $json, int $len, int $pos, string $lit): int
    {
        $n = \strlen($lit);
        if ($pos + $n > $len) {
            return -1;
        }
        for ($i = 0; $i < $n; ++$i) {
            if ($json[$pos + $i] !== $lit[$i]) {
                return -1;
            }
        }

        return $pos + $n;
    }

    private static function stringEnd(string $json, int $len, int $pos): int
    {
        if ($pos >= $len || '"' !== $json[$pos]) {
            return -1;
        }
        $i = $pos + 1;
        while ($i < $len) {
            $c = $json[$i];
            if ('"' === $c) {
                return $i;
            }
            if ('\\' === $c) {
                return -1;
            }
            ++$i;
        }

        return -1;
    }

    private static function parseObject(int $htPtr, string $json, int $len, int $pos): int
    {
        ++$pos;
        $pos = self::skipWs($json, $len, $pos);
        if ($pos < $len && '}' === $json[$pos]) {
            return $pos + 1;
        }
        while ($pos < $len) {
            $pos = self::skipWs($json, $len, $pos);
            $end = self::stringEnd($json, $len, $pos);
            if ($end < 0) {
                return -1;
            }
            $key = \substr($json, $pos + 1, $end - $pos - 1);
            $pos = self::skipWs($json, $len, $end + 1);
            if ($pos >= $len || ':' !== $json[$pos]) {
                return -1;
            }
            ++$pos;
            $pos = self::skipWs($json, $len, $pos);
            if ($pos >= $len) {
                return -1;
            }
            $pos = self::storeAtKeyFlat($htPtr, $key, $json, $len, $pos);
            if ($pos < 0) {
                return -1;
            }
            $pos = self::skipWs($json, $len, $pos);
            if ($pos < $len && ',' === $json[$pos]) {
                ++$pos;
                continue;
            }
            if ($pos < $len && '}' === $json[$pos]) {
                return $pos + 1;
            }

            return -1;
        }

        return -1;
    }

    private static function storeAtKeyFlat(int $htPtr, string $key, string $json, int $len, int $pos): int
    {
        $c = $json[$pos];
        if ('[' === $c) {
            // Index `$json[$pos]` after prior key parse blanks NestedJIT — slice first (#24137).
            $rest = \substr($json, $pos);
            $rlen = \strlen($rest);
            if ($rlen < 2 || '[' !== $rest[0]) {
                return -1;
            }
            $child = phpc_native_ht_alloc();
            $i = 1;
            while ($i < $rlen && (' ' === $rest[$i] || "\t" === $rest[$i] || "\n" === $rest[$i] || "\r" === $rest[$i])) {
                ++$i;
            }
            if ($i < $rlen && ']' === $rest[$i]) {
                phpc_native_ht_set_string_key_ht($htPtr, $key, $child);

                return $pos + $i + 1;
            }
            $index = 0;
            while ($i < $rlen) {
                $neg = false;
                if ('-' === $rest[$i]) {
                    $neg = true;
                    ++$i;
                }
                if ($i >= $rlen || !self::isDigit($rest[$i])) {
                    return -1;
                }
                $val = \ord($rest[$i]) - 48;
                ++$i;
                while ($i < $rlen && self::isDigit($rest[$i])) {
                    $digit = \ord($rest[$i]) - 48;
                    $val = $val * 10;
                    $val = $val + $digit;
                    ++$i;
                }
                if ($neg) {
                    $val = 0 - $val;
                }
                phpc_native_ht_set_long_at($child, $index, $val);
                ++$index;
                while ($i < $rlen && (' ' === $rest[$i] || "\t" === $rest[$i] || "\n" === $rest[$i] || "\r" === $rest[$i])) {
                    ++$i;
                }
                if ($i < $rlen && ',' === $rest[$i]) {
                    ++$i;
                    while ($i < $rlen && (' ' === $rest[$i] || "\t" === $rest[$i] || "\n" === $rest[$i] || "\r" === $rest[$i])) {
                        ++$i;
                    }
                    continue;
                }
                if ($i < $rlen && ']' === $rest[$i]) {
                    ++$i;
                    phpc_native_ht_set_string_key_ht($htPtr, $key, $child);

                    return $pos + $i;
                }

                return -1;
            }

            return -1;
        }
        if ('-' === $c || self::isDigit($c)) {
            $neg = false;
            if ('-' === $c) {
                $neg = true;
                ++$pos;
                if ($pos >= $len) {
                    return -1;
                }
                $c = $json[$pos];
                if (!self::isDigit($c)) {
                    return -1;
                }
            }
            $val = \ord($c) - 48;
            ++$pos;
            while ($pos < $len && self::isDigit($json[$pos])) {
                $digit = \ord($json[$pos]) - 48;
                $val = $val * 10;
                $val = $val + $digit;
                ++$pos;
            }
            if ($neg) {
                $val = 0 - $val;
            }
            phpc_native_ht_set_string_key_long($htPtr, $key, $val);

            return $pos;
        }

        return -1;
    }
}
