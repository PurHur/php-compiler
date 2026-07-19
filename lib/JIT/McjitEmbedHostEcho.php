<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;
use llvm\LLVMValueRef_ptr;

/**
 * MCJIT embed host echo — bind php_write into a module global (#21124, #98).
 *
 * Declared-only externals (even after LLVMAddSymbol) often relocate to null under
 * LLVM 9 MCJIT. A null-initialized function-pointer global filled after
 * createJITCompiler is reliable and routes through PHP's ob-aware SAPI write.
 */
final class McjitEmbedHostEcho
{
    public const WRITE_FN_GLOBAL = '__phpc_embed_php_write_fn';

    public const SNPRINTF_FN_GLOBAL = '__phpc_embed_snprintf_fn';

    public static function ensureGlobals(Context $context): void
    {
        if (Builtin::LOAD_TYPE_EMBED !== $context->loadType) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');

        $writeFnTy = $context->context->functionType($sizeT, false, $i8p, $sizeT);
        self::ensureFnPtrGlobal($context, self::WRITE_FN_GLOBAL, $writeFnTy->pointerType(0));

        $snprintfFnTy = $context->context->functionType($i32, true, $i8p, $sizeT, $i8p);
        self::ensureFnPtrGlobal($context, self::SNPRINTF_FN_GLOBAL, $snprintfFnTy->pointerType(0));
    }

    public static function emitPhpWrite(Context $context, Value $buf, Value $len): void
    {
        self::ensureGlobals($context);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $writeFnTy = $context->context->functionType($sizeT, false, $i8p, $sizeT);
        $global = $context->module->getNamedGlobal(self::WRITE_FN_GLOBAL);
        if (null === $global) {
            throw new \LogicException(self::WRITE_FN_GLOBAL.' missing (#21124)');
        }
        $fnPtr = $context->builder->load($global);
        self::emitIndirectCall(
            $context,
            $writeFnTy,
            $fnPtr,
            $context->builder->pointerCast($buf, $i8p),
            $len
        );
    }

    public static function emitHostSnprintf(
        Context $context,
        Value $buf,
        Value $bufSize,
        Value $fmt,
        Value $arg
    ): Value {
        self::ensureGlobals($context);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $snprintfFnTy = $context->context->functionType($i32, true, $i8p, $sizeT, $i8p);
        $global = $context->module->getNamedGlobal(self::SNPRINTF_FN_GLOBAL);
        if (null === $global) {
            throw new \LogicException(self::SNPRINTF_FN_GLOBAL.' missing (#21124)');
        }
        $fnPtr = $context->builder->load($global);

        return self::emitIndirectCall(
            $context,
            $snprintfFnTy,
            $fnPtr,
            $context->builder->pointerCast($buf, $i8p),
            $bufSize,
            $context->builder->pointerCast($fmt, $i8p),
            $arg
        );
    }

    public static function bindEngine(\PHPLLVM\ExecutionEngine $engine): void
    {
        $write = self::dlsym('php_write');
        if (null !== $write) {
            self::writeFnPtrGlobal($engine, self::WRITE_FN_GLOBAL, $write);
        }
        $snprintf = self::dlsym('snprintf');
        if (null !== $snprintf) {
            self::writeFnPtrGlobal($engine, self::SNPRINTF_FN_GLOBAL, $snprintf);
        }
    }

    /** @param \FFI\CData $fnPtr void* host function */
    private static function writeFnPtrGlobal(\PHPLLVM\ExecutionEngine $engine, string $globalName, \FFI\CData $fnPtr): void
    {
        $slotAddr = $engine->getGlobalValueAddress($globalName);
        if (0 === $slotAddr) {
            return;
        }
        // Result::writeGlobalPointer's FFI::new(..., $addr) treats addr as persistent flag;
        // cast uintptr value → void* → void** then store (MCJIT embed host echo, #21124).
        $addrBox = \FFI::new('uintptr_t');
        $addrBox->cdata = $slotAddr;
        $slot = \FFI::cast('void**', \FFI::cast('void*', $addrBox));
        $slot[0] = $fnPtr;
    }

    /** @return \FFI\CData|null void* */
    private static function dlsym(string $symbol): ?\FFI\CData
    {
        static $dl = null;
        if (null === $dl) {
            $dl = \FFI::cdef('void *dlsym(void *handle, const char *symbol);', 'libdl.so.2');
        }
        $addr = $dl->dlsym(null, $symbol);

        return $addr instanceof \FFI\CData ? $addr : null;
    }

    private static function ensureFnPtrGlobal(Context $context, string $name, $ptrTy): void
    {
        if (null !== $context->module->getNamedGlobal($name)) {
            return;
        }
        $global = $context->module->addGlobal($ptrTy, $name);
        $global->setInitializer($ptrTy->constNull());
    }

    private static function emitIndirectCall(Context $context, $fnTy, Value $fnPtr, Value ...$args): Value
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for embed host echo (#21124)');
        }
        $valueWrapper = $b->llvm->lib->makeArray(
            LLVMValueRef_ptr::class,
            array_map(static fn (Value $value) => $value->value, $args)
        );

        return $b->llvm->factory->value(
            $context->context,
            $b->llvm->lib->LLVMBuildCall2(
                $b->builder,
                $fnTy->type,
                $fnPtr->value,
                $valueWrapper,
                \count($args),
                ''
            )
        );
    }
}
