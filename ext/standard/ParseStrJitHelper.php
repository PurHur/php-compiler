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
}
