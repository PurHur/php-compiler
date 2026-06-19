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
     * Value-box slice of nullsafe short-circuit: PHP null, or uninitialized nullable typed slot (#5220).
     */
    public static function valueBoxShortCircuits(int $typeByte, bool $nullablePropertySlot): bool
    {
        if (Variable::TYPE_NULL === $typeByte) {
            return true;
        }
        if (Variable::TYPE_UNDEFINED === $typeByte && $nullablePropertySlot) {
            return true;
        }

        return false;
    }
}
