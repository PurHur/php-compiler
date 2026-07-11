<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_contains()/str_starts_with()/str_ends_with() for compiled JIT/AOT modules (#14768, php-in-PHP).
 *
 * SSOT: {@see VmString::strpos()}, {@see VmString::startsWith()}, {@see VmString::endsWith()}
 * php-src: ext/standard/string.c
 */
final class StrContainsJitHelper
{
    public static function containsArgv(string $haystack, string $needle): bool
    {
        if ('' === $needle) {
            return true;
        }

        return false !== VmString::strpos($haystack, $needle);
    }

    public static function startsWithArgv(string $haystack, string $needle): bool
    {
        return VmString::startsWith($haystack, $needle);
    }

    public static function endsWithArgv(string $haystack, string $needle): bool
    {
        return VmString::endsWith($haystack, $needle);
    }
}
