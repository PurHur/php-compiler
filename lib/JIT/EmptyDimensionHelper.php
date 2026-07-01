<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * JIT lowering for empty($container[$dim]) — {@see EmptyDimensionLlvm} + {@see \PHPCompiler\VM\VmEmptyDimension} (#14798).
 */
final class EmptyDimensionHelper
{
    public static function compile(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp = null,
        ?Operand $containerOp = null
    ): Value {
        return EmptyDimensionLlvm::compile(
            $context,
            $container,
            $dim,
            $dimOp,
            $containerOp
        );
    }
}
