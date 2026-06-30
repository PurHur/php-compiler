<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * parse_str() for compiled JIT/AOT modules (#9295, php-in-PHP).
 *
 * SSOT: {@see ParseStrEngine} + streaming {@see self::parseDelimitedIntoNative()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrJitHelper
{
    public static function parseInto(HashTable $dest, string $encoded): void
    {
        VmParseStr::mergeInto($dest, ParseStrEngine::parse($encoded));
    }

    /** User-script AOT: materialize delimited pairs into existing native __hashtable__* (#13827, #13900). */
    public static function parseIntoNative(int $destPtr, string $encoded): void
    {
        self::parseDelimitedIntoNative($destPtr, $encoded, '&', false);
    }

    /**
     * Stream delimited pairs into native __hashtable__* without building a full PHP result array (#13900).
     *
     * Flat keys write directly; bracket keys merge one pair at a time so nested-JIT init refresh
     * never foreach's a dynamic {@see ParseStrEngine::parse()} output (SIGSEGV at main_after_init).
     */
    public static function parseDelimitedIntoNative(
        int $destPtr,
        string $encoded,
        string $delimiter,
        bool $cookiePairDecode
    ): void {
        if ($destPtr <= 0 || '' === $encoded) {
            return;
        }

        $pairs = explode($delimiter, $encoded);
        $pairCount = \count($pairs);
        for ($index = 0; $index < $pairCount; ++$index) {
            $pair = $pairs[$index];
            if ('' === $pair) {
                continue;
            }

            if ($cookiePairDecode) {
                $pair = ParseStrEngine::trimCookieSegment($pair);
                if ('' === $pair) {
                    continue;
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
                continue;
            }

            if (!str_contains($key, '[')) {
                phpc_native_ht_set_string_key($destPtr, $key, $value);

                continue;
            }

            self::mergeIntoNative($destPtr, ParseStrEngine::parse($key.'='.$value));
        }
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

    /** Cookie header refresh for user-script AOT superglobals (#13827). */
    public static function parseCookieHeaderInto(HashTable $dest, string $header): void
    {
        if ('' === $header) {
            return;
        }
        foreach (explode(';', $header) as $segment) {
            $segment = trim($segment);
            if ('' === $segment) {
                continue;
            }
            $decoded = urldecode($segment);
            $eq = strpos($decoded, '=');
            if (false === $eq) {
                continue;
            }
            $name = substr($decoded, 0, $eq);
            if ('' === $name) {
                continue;
            }
            $value = substr($decoded, $eq + 1);
            VmParseStr::mergeInto($dest, ParseStrEngine::parse($name.'='.$value));
        }
    }

    /** Cookie header refresh into native __hashtable__* for user-script AOT (#13827, #13900). */
    public static function parseCookieHeaderIntoNative(int $destPtr, string $header): void
    {
        self::parseDelimitedIntoNative($destPtr, $header, ';', true);
    }
}
