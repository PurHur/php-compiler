<?php

declare(strict_types=1);

/**
 * LLVM declaration for __compiler_compact_apply_arg — AOT links phpc_compact.c (#3468).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

final class CompactApplyArg
{
    public static function implement(Context $context): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $charPtrPtr = $charPtr->pointerType(0);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $fnType = $context->context->functionType(
            $context->getTypeFromString('void'),
            false,
            $context->getTypeFromString('__hashtable__*'),
            $valuePtrTy,
            $charPtrPtr,
            $valuePtrTy->pointerType(0),
            $context->getTypeFromString('int64')
        );
        $fn = $context->module->addFunction('__compiler_compact_apply_arg', $fnType);
        $context->registerFunction('__compiler_compact_apply_arg', $fn);
    }
}
