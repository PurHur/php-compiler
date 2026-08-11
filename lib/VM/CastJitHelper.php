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
    /**
     * Whether convert_to_array on a bool should yield [].
     *
     * Always false: Zend wraps both true and false as a one-element array (#30097).
     * Kept as a callable ABI so older linked modules resolve the symbol.
     */
    public static function boolYieldsEmptyArray(bool $value): bool
    {
        return false;
    }
}
