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
     * Value-box slice of nullsafe short-circuit: only null / uninitialized nullable (#26365).
     *
     * Property ?->: scalars fall through and warn (#26365). Method uses
     * {@see valueBoxMethodShortCircuits} (same rule, #26364).
     *
     * php-src: Zend/zend_vm_def.h — nullsafe JMP_NULL / INIT_METHOD_CALL skip IS_NULL only.
     */
    public static function valueBoxShortCircuits(int $typeByte, bool $nullablePropertySlot): bool
    {
        if (Variable::TYPE_NULL === $typeByte) {
            return true;
        }

        return Variable::TYPE_UNDEFINED === $typeByte && $nullablePropertySlot;
    }

    /**
     * Method ?-> value-box short-circuit: only null / uninitialized nullable (#26364).
     *
     * php-src: Zend/zend_vm_def.h — ZEND_INIT_METHOD_CALL nullsafe skips IS_NULL only.
     */
    public static function valueBoxMethodShortCircuits(int $typeByte, bool $nullablePropertySlot): bool
    {
        return self::valueBoxShortCircuits($typeByte, $nullablePropertySlot);
    }
}
