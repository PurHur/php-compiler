<?php

declare(strict_types=1);

/**
 * JIT lowering for isset() — thin trampoline to {@see IssetHelperLlvm} + {@see \PHPCompiler\VM\VmIsset} (#10170).
 */

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCompiler\Lint\UnsupportedFeature;
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

    public static function compileStaticProperty(
        Context $context,
        Operand $classOp,
        ?Operand $nameOp
    ): Value {
        if (!$nameOp instanceof Literal || !is_string($nameOp->value)) {
            UnsupportedFeature::raise('isset-static-property-dynamic-name');
        }

        return $context->type->object->compileStaticPropertyIsSet(
            $context->type->object->resolveClassId($classOp),
            $nameOp->value
        );
    }
}
