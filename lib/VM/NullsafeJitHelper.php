<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for ?-> receiver short-circuit (#10154, php-in-PHP).
 *
 * php-src: Zend/zend_compile.c — ZEND_JMP_NULL chain
 * SSOT: {@see TypedPropertyCheck::nullsafeShortCircuitReceiver}
 */
final class NullsafeJitHelper
{
    /**
     * Value-box slice of nullsafe short-circuit: PHP null, scalar/non-object, or uninitialized
     * nullable typed slot (#5220, #18026, #18028).
     */
    public static function valueBoxShortCircuits(int $typeByte, bool $nullablePropertySlot): bool
    {
        if (Variable::TYPE_NULL === $typeByte) {
            return true;
        }
        if (Variable::TYPE_UNDEFINED === $typeByte && $nullablePropertySlot) {
            return true;
        }

        return \in_array($typeByte, [
            Variable::TYPE_BOOLEAN,
            Variable::TYPE_INTEGER,
            Variable::TYPE_FLOAT,
            Variable::TYPE_STRING,
            Variable::TYPE_ARRAY,
        ], true);
    }
}
