<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Binary-safe substring search for compiled JIT/AOT modules (#15287, php-in-PHP).
 *
 * SSOT: {@see VmString::strpos()} / {@see VmString::stripos()}
 * php-src: ext/standard/string.c — zend_memnstr
 */
final class FindSubstrJitHelper
{
    public const NOT_FOUND = -1;

    public static function findOffsetArgv(string $haystack, string $needle, int $offset): int
    {
        return self::findOffset($haystack, $needle, $offset, false);
    }

    public static function findOffsetCiArgv(string $haystack, string $needle, int $offset): int
    {
        return self::findOffset($haystack, $needle, $offset, true);
    }

    private static function findOffset(string $haystack, string $needle, int $offset, bool $ci): int
    {
        $needleLen = \strlen($needle);
        if (0 === $needleLen) {
            return self::NOT_FOUND;
        }
        $hayLen = \strlen($haystack);
        if ($offset < 0 || $offset + $needleLen > $hayLen) {
            return self::NOT_FOUND;
        }
        $pos = $ci
            ? VmString::stripos($haystack, $needle, $offset)
            : VmString::strpos($haystack, $needle, $offset);

        return false === $pos ? self::NOT_FOUND : $pos;
    }
}
