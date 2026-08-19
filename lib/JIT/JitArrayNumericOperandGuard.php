<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmArrayNumericOperandGuard;

/**
 * JIT trampoline for array ⊙ non-array arithmetic/bitwise TypeErrors (#32346, #32486).
 *
 * SSOT: {@see \PHPCompiler\VM\VmArrayNumericOperandGuard}
 */
final class JitArrayNumericOperandGuard
{
    /**
     * @return bool true when TypeError+abort was emitted (caller must not continue lowering)
     */
    public static function guardArithmetic(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): bool {
        return VmArrayNumericOperandGuard::guardArithmetic($context, $opCode, $left, $right);
    }

    /**
     * @return bool true when TypeError+abort was emitted
     */
    public static function guardUnaryBitwiseNot(Context $context, Variable $var): bool
    {
        return VmArrayNumericOperandGuard::guardUnaryBitwiseNot($context, $var);
    }
}
