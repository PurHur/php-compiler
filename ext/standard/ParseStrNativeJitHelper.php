<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * User-script AOT parse_str native hashtable materializer (#13827, #15417).
 *
 * Split from {@see ParseStrJitHelper} so thin standalone init never nested-JITs
 * HashTable::iterateKeyed paths from parseInto/parseCookieHeaderInto.
 */
final class ParseStrNativeJitHelper
{
    public static function parseIntoNative(int $destPtr, string $encoded): void
    {
        self::parseDelimitedIntoNative($destPtr, $encoded, '&', false);
    }

    public static function parseDelimitedIntoNative(
        int $destPtr,
        string $encoded,
        string $delimiter,
        bool $cookiePairDecode
    ): void {
        if ($destPtr <= 0 || '' === $encoded) {
            return;
        }

        $delimLen = \strlen($delimiter);
        if (0 === $delimLen) {
            return;
        }

        $offset = 0;
        $encodedLen = \strlen($encoded);
        while ($offset <= $encodedLen) {
            $next = strpos($encoded, $delimiter, $offset);
            if (false === $next) {
                $pair = substr($encoded, $offset);
                if ('' !== $pair) {
                    self::parsePairIntoNative($destPtr, $pair, $cookiePairDecode);
                }

                break;
            }

            $pair = substr($encoded, $offset, $next - $offset);
            if ('' !== $pair) {
                self::parsePairIntoNative($destPtr, $pair, $cookiePairDecode);
            }
            $offset = $next + $delimLen;
        }
    }

    private static function parsePairIntoNative(int $destPtr, string $pair, bool $cookiePairDecode): void
    {
        if ($cookiePairDecode) {
            $pair = ParseStrEngine::trimCookieSegment($pair);
            if ('' === $pair) {
                return;
            }
            $pair = ParseStrEngine::urlDecodeComponent($pair);
        }

        $eq = strpos($pair, '=');
        if (false === $eq) {
            $key = $cookiePairDecode ? $pair : ParseStrEngine::urlDecodeComponent($pair);
            $value = '';
        } else {
            $key = $cookiePairDecode
                ? substr($pair, 0, $eq)
                : ParseStrEngine::urlDecodeComponent(substr($pair, 0, $eq));
            $valueStart = $eq + 1;
            $value = $cookiePairDecode
                ? substr($pair, $valueStart)
                : ParseStrEngine::urlDecodeComponent(substr($pair, $valueStart));
        }

        if ('' === $key) {
            return;
        }

        if (!str_contains($key, '[')) {
            phpc_native_ht_set_string_key($destPtr, $key, $value);

            return;
        }

        self::mergeIntoNative($destPtr, ParseStrEngine::parse($key.'='.$value));
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public static function mergeIntoNative(int $destPtr, array $params): void
    {
        if ($destPtr <= 0) {
            return;
        }
        foreach ($params as $key => $value) {
            if (\is_array($value)) {
                $childPtr = (int) phpc_native_ht_alloc();
                self::mergeIntoNative($childPtr, $value);
                if (\is_int($key)) {
                    phpc_native_ht_set_hashtable_at($destPtr, $key, $childPtr);

                    continue;
                }
                phpc_native_ht_set_string_key_ht($destPtr, (string) $key, $childPtr);

                continue;
            }
            if (!\is_string($value)) {
                continue;
            }
            if (\is_int($key)) {
                phpc_native_ht_set_string_at($destPtr, $key, $value);

                continue;
            }
            phpc_native_ht_set_string_key($destPtr, (string) $key, $value);
        }
    }

    public static function parseCookieHeaderIntoNative(int $destPtr, string $header): void
    {
        self::parseDelimitedIntoNative($destPtr, $header, ';', true);
    }
}
