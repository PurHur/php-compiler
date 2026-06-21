<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\VmFromCallable;

/**
 * JIT trampoline for TYPE_FROM_CALLABLE (first-class callable → Closure, #4810, #10272).
 *
 * SSOT: {@see \PHPCompiler\VM\VmFromCallable}
 */
final class FromCallableHelper
{
    public static function createClosureVariable(Context $context, Block $block, OpCode $op): Variable
    {
        return VmFromCallable::createClosureVariable($context, $block, $op);
    }
}
