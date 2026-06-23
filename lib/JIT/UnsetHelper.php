<?php

declare(strict_types=1);

/**
 * JIT lowering for unset() — thin trampoline to {@see UnsetHelperLlvm} + {@see \PHPCompiler\VM\VmUnset} (#10238).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

final class UnsetHelper
{
    public static function compileOffset(
        Context $context,
        Block $block,
        OpCode $op,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        UnsetHelperLlvm::compileOffset($context, $block, $op, $jit);
    }
}
