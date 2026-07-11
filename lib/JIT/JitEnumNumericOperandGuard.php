<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\OpCode;
use PHPCompiler\VM\VmEnumNumericOperandGuard;

/**
 * JIT trampoline for enum-case arithmetic operand guards (#9976).
 *
 * SSOT: {@see \PHPCompiler\VM\VmEnumNumericOperandGuard}
 */
final class JitEnumNumericOperandGuard
{
    public static function guardArithmetic(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): void {
        VmEnumNumericOperandGuard::guardArithmetic($context, $opCode, $left, $right);
    }

    public static function guardPow(Context $context, Variable $base, Variable $exp): void
    {
        VmEnumNumericOperandGuard::guardPow($context, $base, $exp);
    }

    public static function guardModulo(Context $context, Variable $left, Variable $right): void
    {
        VmEnumNumericOperandGuard::guardModulo($context, $left, $right);
    }
}
