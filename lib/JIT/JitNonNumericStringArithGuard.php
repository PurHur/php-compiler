<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmNonNumericStringArithGuard;

/**
 * JIT trampoline for non-numeric string ⊙ arithmetic/bitwise TypeErrors (#34449, #34453).
 *
 * SSOT: {@see \PHPCompiler\VM\VmNonNumericStringArithGuard}
 */
final class JitNonNumericStringArithGuard
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
        return VmNonNumericStringArithGuard::guardArithmetic($context, $opCode, $left, $right);
    }
}
