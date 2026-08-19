<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmObjectNumericOperandGuard;

/**
 * JIT trampoline for object ⊙ scalar arithmetic/bitwise TypeErrors (#32477, #32486).
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
     * @param callable(string):string|null $messageForClass
     * @return bool true when TypeError was emitted
     */
    public static function guardUnary(Context $context, Variable $var, ?callable $messageForClass = null): bool
    {
        return VmObjectNumericOperandGuard::guardUnary($context, $var, $messageForClass);
    }
}
