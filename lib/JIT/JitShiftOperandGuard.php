<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmShiftOperandGuard;

/**
 * JIT trampoline for shift operand guards (#30138).
 *
 * SSOT: {@see \PHPCompiler\VM\VmShiftOperandGuard}
 */
final class JitShiftOperandGuard
{
    public static function guardOperands(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): void {
        VmShiftOperandGuard::guardOperands($context, $opCode, $left, $right);
    }
}
