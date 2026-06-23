<?php

declare(strict_types=1);

/**
 * JIT lowering for isset() — thin trampoline to {@see IssetHelperLlvm} + {@see \PHPCompiler\VM\VmIsset} (#10170).
 */

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPLLVM\Value;

final class IssetHelper
{
    public static function compile(
        Context $context,
        Variable $container,
        ?Variable $dim,
        ?Operand $dimOp = null,
        ?Operand $containerOp = null,
        bool $issetOnProperty = false
    ): Value {
        return IssetHelperLlvm::compile(
            $context,
            $container,
            $dim,
            $dimOp,
            $containerOp,
            $issetOnProperty
        );
    }
}
