<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamLibcHandleKernel;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/** JIT/AOT embed link for libc stream handle table (#9442, #19745). */
final class StreamLibcHandleRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamLibcHandleKernel::ensureLinked($context);
    }

    public static function emitRegisterHandle(Context $context, Value $handle, Value $fpPtr): void
    {
        JitStreamLibcHandleKernel::emitRegisterHandle($context, $handle, $fpPtr);
    }

    public static function emitMarkPopen(Context $context, Value $handle): void
    {
        JitStreamLibcHandleKernel::emitMarkPopen($context, $handle);
    }

    public static function emitClearHandle(Context $context, Value $handle): void
    {
        JitStreamLibcHandleKernel::emitClearHandle($context, $handle);
    }

    public static function emitClearLlvmHandleSlot(Context $context, Value $handle): void
    {
        JitStreamLibcHandleKernel::emitClearLlvmHandleSlot($context, $handle);
    }

    /** @return Value i32 — see {@see JitStreamLibcHandleKernel::emitLibcCloseAndClearLlvmHandleSlot} (#33426) */
    public static function emitLibcCloseAndClearLlvmHandleSlot(
        Context $context,
        Value $handle,
        bool $pclose
    ): Value {
        return JitStreamLibcHandleKernel::emitLibcCloseAndClearLlvmHandleSlot($context, $handle, $pclose);
    }

    /**
     * Combine NestedJIT fclose/pclose result with LLVM-slot libc close (#33426).
     *
     * @return Value i32 ABI result for __compiler_fclose / __compiler_pclose
     */
    public static function emitCloseBridgeResult(
        Context $context,
        LlvmFunction $fn,
        Value $handle,
        Value $helperI32,
        bool $pclose
    ): Value {
        return JitStreamLibcHandleKernel::emitCloseBridgeResult(
            $context,
            $fn,
            $handle,
            $helperI32,
            $pclose
        );
    }
}
