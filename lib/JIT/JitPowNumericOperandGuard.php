<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmPowNumericOperandGuard;

/**
 * JIT trampoline for ** numeric-string operand guards (#9976).
 *
 * SSOT: {@see \PHPCompiler\VM\VmPowNumericOperandGuard}
 */
final class JitPowNumericOperandGuard
{
    public static function guardOperands(Context $context, Variable $base, Variable $exp): void
    {
        VmPowNumericOperandGuard::guardOperands($context, $base, $exp);
    }
}
