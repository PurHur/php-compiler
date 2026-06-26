<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fnmatch() for VM/JIT — pure PHP POSIX matcher (#8016, #7756, #12075).
 *
 * php-src: ext/standard/fnmatch.c — PHP_FUNCTION(fnmatch)
 */
final class VmFnmatch
{
    public const FNM_NOESCAPE = 2;

    public const FNM_PATHNAME = 1;

    public const FNM_PERIOD = 4;

    public const FNM_CASEFOLD = 16;

    public static function available(): bool
    {
        return VmFnmatchPure::available();
    }

    public static function match(string $pattern, string $string, int $flags = 0): bool
    {
        return VmFnmatchPure::match($pattern, $string, $flags);
    }
}
