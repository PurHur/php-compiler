<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for (array)/(object) cast decisions (#10046, php-in-PHP).
 *
 * php-src: Zend/zend_operators.c — convert_to_array / cast_object
 * SSOT: {@see CastSupport}
 */
final class CastJitHelper
{
    /** Zend convert_to_array: false bool yields empty array, true wraps at index 0. */
    public static function boolYieldsEmptyArray(bool $value): bool
    {
        return !$value;
    }
}
