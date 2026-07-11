<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strspn()/strcspn() for compiled JIT/AOT modules (#14700, php-in-PHP).
 *
 * SSOT: {@see VmString::strspn()} / {@see VmString::strcspn()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strspn), PHP_FUNCTION(strcspn)
 */
final class StrspnJitHelper
{
    /** Two-arg JIT bridge — offset/length use {@see extendedArgvInt}. */
    public static function strspnArgv(string $str, string $mask): int
    {
        return VmString::strspn($str, $mask);
    }

    /** Two-arg JIT bridge — offset/length use {@see extendedArgvInt}. */
    public static function strcspnArgv(string $str, string $mask): int
    {
        return VmString::strcspn($str, $mask);
    }

    public static function extendedArgv(
        string $str,
        string $mask,
        int $offset,
        int $length,
        bool $lenIsNull,
        bool $isStrspn
    ): int {
        $len = $lenIsNull ? null : $length;

        return $isStrspn
            ? VmString::strspn($str, $mask, $offset, $len)
            : VmString::strcspn($str, $mask, $offset, $len);
    }

    /** Nested-JIT bridge entry — bool flags as i32 (#14700). */
    public static function extendedArgvInt(
        string $str,
        string $mask,
        int $offset,
        int $length,
        int $lenIsNull,
        int $isStrspn
    ): int {
        return self::extendedArgv(
            $str,
            $mask,
            $offset,
            $length,
            0 !== $lenIsNull,
            0 !== $isStrspn
        );
    }
}
