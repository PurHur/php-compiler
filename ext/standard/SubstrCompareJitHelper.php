<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * substr_compare() for compiled JIT/AOT modules (#13536, php-in-PHP).
 *
 * SSOT: {@see VmString::substr_compare()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(substr_compare)
 */
final class SubstrCompareJitHelper
{
    public static function substrCompareArgv(
        string $haystack,
        string $needle,
        int $offset,
        int $length,
        bool $caseInsensitive
    ): int {
        $lengthArg = $length < 0 ? null : $length;

        return VmString::substr_compare($haystack, $needle, $offset, $lengthArg, $caseInsensitive);
    }
}
