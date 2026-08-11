<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\OpCode;

/**
 * Lowered into JIT/AOT modules for shift operand TypeError guards (#30138, php-in-PHP).
 *
 * php-src: Zend/zend_operators.c — shift_left_function / shift_right_function
 * SSOT: {@see Variable::validateShiftOperands}
 */
final class ShiftOperandJitHelper
{
    /**
     * Validates shift operands from boxed locals; throws catchable TypeError when invalid.
     */
    public static function guardShiftOperands(int $opCode, Variable $left, Variable $right): void
    {
        Variable::validateShiftOperands($opCode, $left, $right);
    }

    /**
     * ABI bridge: validate a pair of value boxes (may alias for unary-ish probes).
     */
    public static function guardShiftValueBoxPair(int $opCode, Variable $left, Variable $right): void
    {
        Variable::validateShiftOperands($opCode, $left, $right);
    }

    public static function operatorSymbol(int $opCode): string
    {
        return match ($opCode) {
            OpCode::TYPE_SHIFT_LEFT => '<<',
            OpCode::TYPE_SHIFT_RIGHT => '>>',
            default => '?',
        };
    }
}
