<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * parse_str() for compiled JIT/AOT modules (#9295, php-in-PHP).
 *
 * SSOT: {@see ParseStrEngine} + {@see VmParseStr::mergeInto()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrJitHelper
{
    public static function parseInto(HashTable $dest, string $encoded): void
    {
        VmParseStr::mergeInto($dest, ParseStrEngine::parse($encoded));
    }

    /** User-script AOT: materialize ParseStrEngine output into existing native __hashtable__* (#13827). */
    public static function parseIntoNative(int $destPtr, string $encoded): void
    {
        if ($destPtr <= 0) {
            return;
        }
        self::mergeIntoNative($destPtr, ParseStrEngine::parse($encoded));
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

    /** Cookie header refresh into native __hashtable__* for user-script AOT (#13827). */
    public static function parseCookieHeaderIntoNative(int $destPtr, string $header): void
    {
        if ($destPtr <= 0 || '' === $header) {
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
            self::mergeIntoNative($destPtr, ParseStrEngine::parse($name.'='.$value));
        }
    }
}
