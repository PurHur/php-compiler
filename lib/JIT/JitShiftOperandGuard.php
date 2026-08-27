<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmShiftOperandGuard;

/**
 * JIT trampoline for shift operand guards (#30138, #35308).
 *
 * SSOT: {@see \PHPCompiler\VM\VmShiftOperandGuard}
 */
final class JitShiftOperandGuard
{
    /**
     * @return bool true when compile-time TypeError+abort was emitted (caller must not continue lowering)
     */
    public static function guardOperands(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): bool {
        return VmShiftOperandGuard::guardOperands($context, $opCode, $left, $right);
    }
}
