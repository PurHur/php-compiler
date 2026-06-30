<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native parse_str() query parser — no host PHP \parse_str() (issues #6013, #6308).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrEngine
{
    private const MAX_KEY_PARTS = 64;

    /**
     * @return array<string, mixed>
     */
    public static function parse(string $encoded, string $delimiter = '&'): array
    {
        return self::parseDelimited($encoded, $delimiter, false);
    }

    /**
     * Delimited key/value parser shared by parse_str(), superglobals, and JIT compile-time lowering (#6308).
     *
     * @param bool $cookiePairDecode when true, trim + url-decode each pair before splitting on '=' (Cookie header)
     *
     * @return array<string, mixed>
     */
    public static function parseDelimited(string $encoded, string $delimiter, bool $cookiePairDecode): array
    {
        if ('' === $encoded) {
            return [];
        }

        $result = [];
        foreach (explode($delimiter, $encoded) as $pair) {
            if ('' === $pair) {
                continue;
            }
            if ($cookiePairDecode) {
                $pair = self::trimWs($pair);
                if ('' === $pair) {
                    continue;
                }
                $pair = self::urlDecode($pair);
            }
            $eq = strpos($pair, '=');
            if (false === $eq) {
                $key = $cookiePairDecode ? $pair : self::urlDecode($pair);
                $value = '';
            } else {
                $key = $cookiePairDecode ? substr($pair, 0, $eq) : self::urlDecode(substr($pair, 0, $eq));
                $value = $cookiePairDecode ? substr($pair, $eq + 1) : self::urlDecode(substr($pair, $eq + 1));
            }
            if ('' === $key) {
                continue;
            }
            self::assignParam($result, $key, $value);
        }

        return $result;
    }

    private static function trimWs(string $value): string
    {
        return trim($value, " \t\r\n");
    }

    private static function urlDecode(string $value): string
    {
        $value = str_replace('+', ' ', $value);
        if (!str_contains($value, '%')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/%[0-9A-Fa-f]{2}/',
            static function (array $m): string {
                return \chr((int) hexdec(substr($m[0], 1)));
            },
            $value
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function assignParam(array &$result, string $key, string $value): void
    {
        $bracketPos = strpos($key, '[');
        if (false === $bracketPos) {
            $result[$key] = $value;

            return;
        }

        $parsed = self::parseKeyBrackets($key);
        if (null === $parsed) {
            return;
        }

        self::setNestedValue($result, $parsed['parts'], $parsed['append'], $value);
    }

    /**
     * Bracket key structure for native parse_str materializers (#13900).
     *
     * @return array{parts: list<string>, append: bool}|null
     */
    public static function parseKeyStructure(string $key): ?array
    {
        return self::parseKeyBrackets($key);
    }

    /** URL-decode a query component (shared with native streaming parsers). */
    public static function urlDecodeComponent(string $value): string
    {
        return self::urlDecode($value);
    }

    /** Trim whitespace from a cookie segment. */
    public static function trimCookieSegment(string $value): string
    {
        return self::trimWs($value);
    }

    /**
     * @return array{parts: list<string>, append: bool}|null
     */
    private static function parseKeyBrackets(string $key): ?array
    {
        if ('' === $key) {
            return null;
        }

        $bracketPos = strpos($key, '[');
        $parts = [];
        $append = false;

        if (false !== $bracketPos) {
            $base = substr($key, 0, $bracketPos);
            if ('' !== $base) {
                $parts[] = $base;
            }
            $pos = $bracketPos;
            $len = \strlen($key);
            while ($pos < $len && '[' === $key[$pos]) {
                ++$pos;
                if ($pos >= $len) {
                    return null;
                }
                if (']' === $key[$pos]) {
                    $append = true;
                    ++$pos;
                    continue;
                }
                $close = strpos($key, ']', $pos);
                if (false === $close) {
                    return null;
                }
                $parts[] = substr($key, $pos, $close - $pos);
                $pos = $close + 1;
                if ($pos < $len && '[' === $key[$pos] && $pos + 1 < $len && ']' === $key[$pos + 1]) {
                    $append = true;
                    $pos += 2;
                }
            }
            if ($pos !== $len) {
                return null;
            }
        } else {
            $parts[] = $key;
        }

        if ([] === $parts || \count($parts) > self::MAX_KEY_PARTS) {
            return null;
        }

        return ['parts' => $parts, 'append' => $append];
    }

    /**
     * @param array<string, mixed> $root
     * @param list<string>         $parts
     */
    private static function setNestedValue(array &$root, array $parts, bool $append, string $value): void
    {
        if ([] === $parts) {
            return;
        }

        $cursor = &$root;
        $last = \count($parts) - 1;
        for ($i = 0; $i < $last; ++$i) {
            $part = $parts[$i];
            if (!\is_array($cursor)) {
                $cursor = [];
            }
            if (!\array_key_exists($part, $cursor) || !\is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }

        $leaf = $parts[$last];
        if (!\is_array($cursor)) {
            $cursor = [];
        }

        if ($append) {
            if (!\array_key_exists($leaf, $cursor) || !\is_array($cursor[$leaf])) {
                $cursor[$leaf] = [];
            }
            $cursor[$leaf][] = $value;

            return;
        }

        $cursor[$leaf] = $value;
    }
}
