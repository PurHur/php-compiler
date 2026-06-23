<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * empty($obj->prop) — thin trampoline to {@see EmptyObjectPropertyLlvm} + {@see \PHPCompiler\VM\VmEmpty} (#10268).
 *
 * Uninitialized typed slots are empty without read (#6787, zend_object_handlers.c);
 * __isset semantics otherwise (#3298).
 */
final class EmptyObjectPropertyHelper
{
    public static function compile(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp,
        ?Operand $containerOp
    ): Value {
        return EmptyObjectPropertyLlvm::compile(
            $context,
            $container,
            $dim,
            $dimOp,
            $containerOp
        );
    }

    public static function compileEmptyFromValue(Context $context, Variable $var): Value
    {
        return EmptyObjectPropertyLlvm::compileEmptyFromValue($context, $var);
    }
}
