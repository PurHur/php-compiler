<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** fnmatch() flag constants — php-src ext/standard/fnmatch.c (PHP FNM_* values). */
final class VmFnmatch
{
    public const FNM_NOESCAPE = 2;
    public const FNM_PATHNAME = 1;
    public const FNM_PERIOD = 4;
    public const FNM_CASEFOLD = 16;

    public static function match(string $pattern, string $string, int $flags = 0): bool
    {
        if (!\function_exists('fnmatch')) {
            throw new \LogicException('fnmatch() requires host fnmatch() in this compiler build');
        }

        return \fnmatch($pattern, $string, $flags);
    }
}
