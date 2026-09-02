<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Refcount;
use PHPLLVM\Value;

/**
 * Emit compile-time string literals as static immortal {@see __string__} globals (#36194).
 *
 * Replaces eager {@see Context::constantStringFromString} __init__ calls to
 * {@code __string__init} with a packed struct initializer — peer of Zend interned
 * strings (php-src Zend/zend_string.h).
 */
final class StaticImmortalStringLlvm
{
    /**
     * Create a module-local {@code __string__*} global whose initializer points at
     * a static packed {@code __string__} body (immortal, non-refcounted).
     */
    public static function definePtrGlobal(Context $context, string $string, string $ptrGlobalName): Value
    {
        $len = \strlen($string);
        // Match __string__alloc: payload plus one byte for the trailing NUL.
        $allocSize = $len + 1;

        $llvm = $context->llvm;
        $lib = $llvm->lib;
        $factory = $llvm->factory;
        $llvmCtx = $context->context;

        $i32 = $llvmCtx->int32Type();
        $i64 = $llvmCtx->int64Type();
        $i8 = $llvmCtx->int8Type();
        $stringPtrTy = $context->type->string->pointer;

        $typeinfo = Refcount::TYPE_INFO_TYPE_STRING;

        $bytes = [];
        for ($i = 0; $i < $len; ++$i) {
            $bytes[] = $i8->constInt(\ord($string[$i]), false);
        }
        $bytes[] = $i8->constInt(0, false);
        $valueArrayTy = $i8->arrayType($allocSize);
        $valueInit = $factory->value(
            $llvmCtx,
            $lib->LLVMConstArray(
                $i8->type,
                $lib->makeArray(\llvm\LLVMValueRef_ptr::class, \array_map(
                    static fn (Value $v) => $v->value,
                    $bytes
                )),
                $allocSize
            )
        );

        // Flatten __ref__ into i32 refcount + i32 typeinfo so the packed body initializer
        // matches the anonymous struct type exactly (nested %__ref__ consts fail verify).
        $bodyStructTy = $llvmCtx->structType(
            true,
            $i32,
            $i32,
            $i64,
            $valueArrayTy
        );
        $bodyInit = self::constStruct(
            $llvm,
            $bodyStructTy,
            [
                $i32->constInt(0, false),
                $i32->constInt($typeinfo, false),
                $i64->constInt($len, false),
                $valueInit,
            ],
            true
        );

        $bodyGlobal = $context->module->addGlobal(
            $bodyStructTy,
            $ptrGlobalName.'_body'
        );
        $bodyGlobal->setInitializer($bodyInit);
        $lib->LLVMSetGlobalConstant($bodyGlobal->value, $llvm->toBool(true));
        $lib->LLVMSetUnnamedAddr($bodyGlobal->value, $llvm->toBool(true));

        $zero = $i32->constInt(0, false);
        $gepIndices = $lib->makeArray(\llvm\LLVMValueRef_ptr::class, [$zero->value]);
        $bodyPtr = $factory->value(
            $llvmCtx,
            $lib->LLVMConstGEP($bodyGlobal->value, $gepIndices, 1)
        );
        $stringPtrInit = $factory->value(
            $llvmCtx,
            $lib->LLVMConstBitCast($bodyPtr->value, $stringPtrTy->type)
        );

        $ptrGlobal = $context->module->addGlobal($stringPtrTy, $ptrGlobalName);
        $ptrGlobal->setInitializer($stringPtrInit);
        $lib->LLVMSetGlobalConstant($ptrGlobal->value, $llvm->toBool(true));

        return $ptrGlobal;
    }

    /** @param list<Value> $fieldValues */
    private static function constStruct(
        \PHPLLVM\LLVM $llvm,
        \PHPLLVM\Type $structTy,
        array $fieldValues,
        bool $packed
    ): Value {
        $lib = $llvm->lib;
        $factory = $llvm->factory;
        $llvmCtx = $structTy->context;

        $structVals = $lib->makeArray(
            \llvm\LLVMValueRef_ptr::class,
            \array_map(static fn (Value $v) => $v->value, $fieldValues)
        );

        return $factory->value(
            $llvmCtx,
            $lib->LLVMConstStructInContext(
                $llvmCtx->context,
                $structVals,
                \count($fieldValues),
                $llvm->toBool($packed)
            )
        );
    }
}
