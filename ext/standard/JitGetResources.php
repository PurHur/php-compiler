<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_resources() via __compiler_get_resources (#3646). */
final class JitGetResources
{
    public static function invoke(Context $context, ?JITVariable $typeArg): Value
    {
        $strPtrTy = $context->getTypeFromString('__string__*');
        $typePtr = $strPtrTy->constNull();
        if (null !== $typeArg && JITVariable::TYPE_NULL !== $typeArg->type) {
            if (null !== $typeArg->compileTimeString) {
                get_resources_::normalizeType($typeArg->compileTimeString);
            }
            $typePtr = JitStringArg::lower($context, $typeArg, 'get_resources() type');
        }
        $ht = $context->builder->call(
            $context->lookupFunction('__compiler_get_resources'),
            $typePtr
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->refcount->addref($ht);

        return $ptr;
    }
}
