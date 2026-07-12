<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;
use llvm\llvm as lib;

/**
 * Register a helper unit's __init__ in llvm.global_ctors (#16075 step 4).
 *
 * Each cached helper TU runs its init at load time via the appending
 * @llvm.global_ctors global, so user-script AOT no longer needs to call
 * unit inits from the script __init__ chain (which aliased muldefs-merged
 * globals and broke echo of short literals — #17069).
 */
final class HelperUnitGlobalCtor
{
    private const CTOR_PRIORITY = 65535;

    public static function register(Context $context, string $initSymbol): void
    {
        $initFn = $context->module->getNamedFunction($initSymbol);
        if (null === $initFn) {
            return;
        }

        $llvm = $context->llvm;
        $lib = $llvm->lib;
        $factory = $llvm->factory;
        $llvmCtx = $context->context;

        $i32 = $llvmCtx->int32Type();
        $i8Ptr = $context->getTypeFromString('int8*');
        $voidFnTy = $llvmCtx->functionType($llvmCtx->voidType(), false);
        $voidFnPtrTy = $voidFnTy->pointerType(0);

        $ctorStructTy = $llvmCtx->structType(false, $i32, $voidFnPtrTy, $i8Ptr);
        $priority = $i32->constInt(self::CTOR_PRIORITY, false);
        $fnPtr = $factory->value(
            $llvmCtx,
            $lib->LLVMConstBitCast($initFn->value, $voidFnPtrTy->type)
        );
        $nullData = $i8Ptr->constNull();

        $structVals = $lib->makeArray(
            \llvm\LLVMValueRef_ptr::class,
            [$priority->value, $fnPtr->value, $nullData->value]
        );
        $ctorEntry = $factory->value(
            $llvmCtx,
            $lib->LLVMConstStructInContext(
                $llvmCtx->context,
                $structVals,
                3,
                $llvm->toBool(false)
            )
        );

        $arrayVals = $lib->makeArray(\llvm\LLVMValueRef_ptr::class, [$ctorEntry->value]);
        $arrayInit = $factory->value(
            $llvmCtx,
            $lib->LLVMConstArray($ctorStructTy->type, $arrayVals, 1)
        );

        $arrayTy = $ctorStructTy->arrayType(1);
        $global = $context->module->addGlobal($arrayTy, 'llvm.global_ctors');
        $lib->LLVMSetLinkage($global->value, lib::LLVMAppendingLinkage);
        $lib->LLVMSetInitializer($global->value, $arrayInit->value);
        $lib->LLVMAddNamedMetadataOperand($context->module->module, 'llvm.global_ctors', $global->value);
    }
}
