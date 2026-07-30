<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT bridge for internal length-limited binary compare — {@see StringNCompare} (#15364).
 * Userland memcmp() is not registered (#25359).
 */
final class StringMemcmp
{
    public static function ensureLinked(Context $context): void
    {
        StringNCompare::ensureMemcmpLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $left,
        Value $right,
        Value $length
    ): Value {
        return StringNCompare::invokeMemcmp($context, $left, $right, $length);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
