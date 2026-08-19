<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmObjectNumericOperandGuard;

/**
 * JIT trampoline for object ⊙ scalar arithmetic TypeErrors (#32477).
 *
 * SSOT: {@see \PHPCompiler\VM\VmObjectNumericOperandGuard}
 */
final class JitObjectNumericOperandGuard
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
        return VmObjectNumericOperandGuard::guardArithmetic($context, $opCode, $left, $right);
    }

    /**
     * @return bool true when TypeError was emitted
     */
    public static function guardUnary(Context $context, Variable $var): bool
    {
        return VmObjectNumericOperandGuard::guardUnary($context, $var);
    }
}
